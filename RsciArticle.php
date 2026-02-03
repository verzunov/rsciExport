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
        $languages= array('ru', 'en','ky');
        $titlesElement = $this->articleElement->addChild("artTitles");
        foreach ($languages as $lang) {
            $title = $this->publication->getData('title', $lang);
            $titleElement=$titlesElement->addChild("artTitle", $title);
            $titleElement->addAttribute('lang', strtoupper(LocaleConversion::get3LetterIsoFromLocale($lang)));
        }
        $abstractsElement = $this->articleElement->addChild("abstracts");
        foreach ($languages as $lang) {
            $abstract = $this->publication->getData('abstract', $lang);

            $abstractElement=$abstractsElement->addChild("abstract", strip_tags($this->xmlSafe((string)$abstract)));
            $abstractElement->addAttribute('lang', strtoupper(LocaleConversion::get3LetterIsoFromLocale($lang)));
        }
        $textElement = $this->articleElement->addChild("text",$this->text);
        $textElement->addAttribute('lang', $this->langPubl);

        $codesElement = $this->articleElement->addChild("codes");
        $codesElement->addChild('udk', $this->udk);
        $keywordsElement = $this->articleElement->addChild("keywords");

        foreach ($languages as $lang) {
            $kwList = $this->publication->getData('keywords', $lang);

            if (empty($kwList) || !is_array($kwList)) {
                continue;
            }

            $kwdGroupElement = $keywordsElement->addChild("kwdGroup");
            $kwdGroupElement->addAttribute('lang', strtoupper(LocaleConversion::get3LetterIsoFromLocale($lang)));

            foreach ($kwList as $kwRaw) {
                // поддержка случая "слово1, слово2; слово3"
                $parts = preg_split('/[,;]/u', (string)$kwRaw);
                foreach ($parts as $kw) {
                    $kw = trim(str_replace('.', '', $kw));
                    if ($kw === '') continue;

                    // лучше XML-safe (если вдруг & / кавычки / спецсимволы)
                    $kwdGroupElement->addChild("keyword", $this->xmlSafe($kw));
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

            // 1) найдём PDF-галлей, а не просто первый
            $pdfGalley = null;
            foreach ($this->galleys as $g) {
                $sid = (int) $g->getData('submissionFileId');
                if (!$sid) {
                    continue;
                }

                $f = Repo::submissionFile()->get($sid);
                if (!$f) {
                    continue;
                }

                $path = (string) $f->getData('path');
                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mime = (string) $f->getData('mimetype'); // иногда есть, иногда нет

                // критерий PDF
                if ($ext === 'pdf' || $mime === 'application/pdf') {
                    $pdfGalley = $g;
                    break;
                }
            }

            if (!$pdfGalley) {
                error_log("RSCI Export: не найден PDF galley для публикации " . $this->publication->getId());
            } else {

                $submissionId = (int) $pdfGalley->getData('submissionFileId');
                $file = Repo::submissionFile()->get($submissionId);

                if (!$file) {
                    error_log("RSCI Export: submissionFile {$submissionId} не найден");
                } else {

                    $filePath = (string) $file->getData('path');
                    $filesDir = (string) Config::getVar('files', 'files_dir'); // у вас /var/ojs-files/pau

                    // В OJS path обычно относительный (journals/..../file.pdf)
                    $fullPath = (strpos($filePath, DIRECTORY_SEPARATOR) === 0)
                        ? $filePath
                        : rtrim($filesDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($filePath, DIRECTORY_SEPARATOR);

                    $fileName = basename($filePath);
                    $target   = rtrim($this->projectFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

                    error_log("RSCI Export: пробую копировать PDF: sid={$submissionId}; fullPath={$fullPath}; target={$target}");

                    if (!file_exists($fullPath)) {
                        error_log("RSCI Export: файл не найден: {$fullPath}");
                    } elseif (!is_readable($fullPath)) {
                        error_log("RSCI Export: нет прав на чтение: {$fullPath}");
                    } elseif (!is_dir($this->projectFolder)) {
                        error_log("RSCI Export: projectFolder не существует: {$this->projectFolder}");
                    } elseif (!is_writable($this->projectFolder)) {
                        error_log("RSCI Export: нет прав на запись в projectFolder: {$this->projectFolder}");
                    } else {
                        $ok = @copy($fullPath, $target);
                        if (!$ok) {
                            $err = error_get_last();
                            error_log("RSCI Export: copy() failed: " . ($err['message'] ?? 'unknown error'));
                        } else {
                            error_log("RSCI Export: PDF скопирован OK: {$target}");
                        }
                    }

                    // XML-описание файла (добавляйте только если реально скопировали)
                    if (file_exists($target)) {
                        $filesElement = $this->articleElement->addChild("files");
                        $fileElement = $filesElement->addChild("file", $fileName);
                        $fileElement->addAttribute('desc', 'fullText');
                    }
                }
            }

        } else {
            error_log("RSCI Export: у публикации нет галлеев (publicationId=" . $this->publication->getId() . ")");
        }


        //$text=$this->parsePdf($fullPath);
    }
}