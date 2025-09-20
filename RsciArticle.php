<?php

namespace APP\plugins\importexport\rsciExport;
use APP\facades\Repo;
use APP\publication\Publication;
use PKP\i18n\LocaleConversion;
use APP\core\Services;
use SimpleXMLElement;
use APP\core\Application;
use PKP\config\Config;
use Smalot\PdfParser\Parser;
class RsciArticle
{
    private Publication $publication;
    private $articleElement;
    private string $pages;
    private string $artType;
    private string $langPubl;
    private string $text;
    /**
     * @var mixed|null
     */
    private mixed $udk;
    /**
     * @var mixed|null
     */
    private string $citationsRaw;
    private $galleys;
    private string $projectFolder;

    /**
     * @param mixed $articleElement
     * @param mixed $article
     */
    public function __construct(mixed $articleElement, Publication $publication, string $projectFolder)
    {
        $this->articleElement = $articleElement;
        $this->publication = $publication;
        $this->pages = sprintf("%s - %s",$publication->getStartingPage(),$publication->getEndingPage());
        $this->artType = "RAR";
        $this->langPubl = strtoupper(LocaleConversion::get3LetterIsoFromLocale($publication->getData('locale')));
        $this->text=str_repeat('*', 500);
        $subjects = $publication->getData('subjects', $publication->getData('locale'));
        $this->udk = is_array($subjects) && count($subjects) > 0 ? $subjects[0] : '';
        $this->projectFolder = $projectFolder;

        $this->citationsRaw=$publication->getData('citationsRaw');
        $this->galleys= $publication->getData('galleys')->toArray();
    }
    /**
     * Удаляет лидирующие цифры и точки из строки.
     *
     * @param string $input Входная строка.
     * @return string Строка без лидирующих цифр и точек.
     */
   public function removeLeadingDigitsAndDots(string $input): string {
        return preg_replace('/^[\d.]+/', '', $input);
    }
    private function parsePdf($pdfFilePath)
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($pdfFilePath);
        return $pdf->getText();
    }
    /** Делает текст XML-безопасным: убирает HTML, декодит HTML-сущности, меняет NBSP на пробел, экранирует для XML */
    private function xmlSafe(string $s): string {
        // 1) Убираем HTML
        $s = strip_tags($s);

        // 2) Декодируем HTML-сущности (&nbsp;, &mdash; и т.п.) в Юникод
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // ENT_HTML5 знает &nbsp;

        // 3) Меняем неразрывный пробел U+00A0 на обычный пробел (или оставьте как есть — это валидно)
        $s = preg_replace('/\x{00A0}/u', ' ', $s);

        // 4) Экранируем для XML (важно: ENT_XML1)
        return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    public function getXML()
    {
        $this->articleElement->addChild("pages", $this->pages);
        $this->articleElement->addChild("artType", $this->artType);
        $this->articleElement->addChild("langPubl", $this->langPubl);
        $authorsElement = $this->articleElement->addChild("authors");
        $num=0;
        foreach ($this->publication->getData('authors') as $index=>$author) {
            $authorElement=$authorsElement->addChild("author");
            $rsciAuthor = new RsciAuthor($authorElement, $author,++$num);
            $rsciAuthor->toXML();
        }
        $languages= array('ru', 'en');
        $titlesElement = $this->articleElement->addChild("artTitles");
        foreach ($languages as $lang) {
            $title = $this->publication->getData('title', $lang);
            $titleElement=$titlesElement->addChild("artTitle", $title);
            $titleElement->addAttribute('lang', strtoupper(LocaleConversion::get3LetterIsoFromLocale($lang)));
        }
        $abstractsElement = $this->articleElement->addChild("abstracts");
        foreach ($languages as $lang) {
            $abstract = $this->publication->getData('abstract', $lang);

            $abstractElement=$abstractsElement->addChild("abstract", strip_tags($this->xmlSafe($abstract)));
            $abstractElement->addAttribute('lang', strtoupper(LocaleConversion::get3LetterIsoFromLocale($lang)));
        }
        $textElement = $this->articleElement->addChild("text",$this->text);
        $textElement->addAttribute('lang', $this->langPubl);

        $codesElement = $this->articleElement->addChild("codes");
        $codesElement->addChild('udk', $this->udk);
        $keywordsElement = $this->articleElement->addChild("keywords");
        foreach ($languages as $lang) {
            $keywords = $this->publication->getData('keywords', $lang);
            if (!empty($keywords) && is_array($keywords)) {
                $keywords = preg_split("/[,;]/", $keywords[0]);
                $kwdGroupElement = $keywordsElement->addChild("kwdGroup");
                $kwdGroupElement->addAttribute('lang', strtoupper(LocaleConversion::get3LetterIsoFromLocale($lang)));
                foreach ($keywords as $keyword) {
                    $kwdGroupElement->addChild("keyword", str_replace('.', '', trim($keyword)));
                }
            }

        }
        $citations=preg_split("/\r\n|\n|\r/",$this->citationsRaw);
        $referencesElement = $this->articleElement->addChild("references");

        foreach ($citations as $citation) {
            if ($citation=="") {continue;}
            $referenceElement=$referencesElement->addChild("reference");
            $refInfoElement=$referenceElement->addChild('refInfo');
            $refInfoElement->addAttribute('lang', $this->langPubl);
            $refInfoElement->addChild('text', htmlspecialchars($this->removeLeadingDigitsAndDots(trim($citation))));

        }
        if (!empty($this->galleys)) {
            $submissionId = $this->galleys[0]->getData('submissionFileId');
            if ($submissionId) {
                $file = Repo::submissionFile()->get($submissionId);
                if ($file) {
                    $filePath = $file->getData('path');
                    $relativePath = Config::getVar('files', 'files_dir');
                    $absolutePath = realpath($relativePath);
                    $fullPath = $absolutePath . DIRECTORY_SEPARATOR . $filePath;
                    $fileName = basename($filePath);

                    if (file_exists($fullPath)) {
                        copy($fullPath, $this->projectFolder . DIRECTORY_SEPARATOR . $fileName);
                    } else {
                        error_log("RSCI Export: файл {$fullPath} не найден");
                    }

                    $filesElement = $this->articleElement->addChild("files");
                    $fileElement = $filesElement->addChild("file", $fileName);
                    $fileElement->addAttribute('desc', 'fullText');
                } else {
                    error_log("RSCI Export: submissionFile {$submissionId} не найден");
                }
            } else {
                error_log("RSCI Export: у галлея нет submissionFileId");
            }
        } else {
            error_log("RSCI Export: у публикации нет галлеев");
        }

        //$text=$this->parsePdf($fullPath);
    }
}