<?php
/**
 * @file RsciExportPlugin.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class RsciExportPlugin
 * @brief An rsci plugin demonstrating how to write an import/export plugin.
 */

namespace APP\plugins\importexport\rsciExport;
use Exception;
use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;

use APP\template\TemplateManager;
use Illuminate\Support\LazyCollection;
use PKP\plugins\ImportExportPlugin;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
// В самом верху RsciExportPlugin.php
(function () {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
        // Используем composer, если зависимости действительно установлены
        require_once $autoload;
        return;
    }

    // Fallback без composer: подключаем свои классы вручную
    $files = [
        __DIR__ . '/RsciJournal.php',
        __DIR__ . '/RsciIssue.php',
        __DIR__ . '/RsciArticle.php',
        __DIR__ . '/RsciAuthor.php',
        __DIR__ . '/RsciJournalInfo.php',
    ];
    foreach ($files as $f) {
        if (is_file($f)) {
            require_once $f;
        }
    }
})();



class RsciExportPlugin extends ImportExportPlugin
{
    public function register($category, $path, $mainContextId = NULL)
    {
        error_log("Тут зашёл в register()");
        $success = parent::register($category, $path);

        $this->addLocaleData();

        return $success;
    }

    public function getName()
    {
        return 'rsciExport';
    }

    public function getDisplayName()
    {
        return __('plugins.importexport.rsciExport.name');
    }

    public function getDescription()
    {
        return __('plugins.importexport.rsciExport.description');
    }
    public function createTempDirectory($prefix = 'temp_') {
        // Получаем путь к системной временной директории
        $tempDir = sys_get_temp_dir();

        // Генерируем уникальное имя для временной папки
        $uniqueDir = $tempDir . DIRECTORY_SEPARATOR . $prefix . uniqid();

        // Создаем директорию
        if (mkdir($uniqueDir, 0700, true)) {
            return $uniqueDir; // Возвращаем путь к временной папке
        } else {
            throw new Exception("Не удалось создать временную папку.");
        }
    }
    public function createZipInSameDirectory($directory) {
        // Проверяем, существует ли директория
        if (!is_dir($directory)) {
            throw new Exception("Директория не существует: $directory");
        }

        // Получаем родительский путь и имя папки
        $parentDir = dirname($directory);
        $folderName = basename($directory);

        // Путь к создаваемому ZIP-архиву
        $zipFilePath = $parentDir . DIRECTORY_SEPARATOR . $folderName . '.zip';

        // Создаем новый объект ZipArchive
        $zip = new ZipArchive();

        // Открываем или создаем ZIP-архив
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Не удалось создать ZIP-архив: $zipFilePath");
        }

        // Рекурсивная функция для добавления файлов в архив
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($directory) + 1);

            if ($file->isDir()) {
                // Добавляем папку
                $zip->addEmptyDir($relativePath);
            } else {
                // Добавляем файл
                $zip->addFile($filePath, $relativePath);
            }
        }

        // Закрываем архив
        $zip->close();

        return $zipFilePath; // Возвращаем путь к архиву
    }
    public function downloadZipArchive($zipFilePath) {
        // Проверяем, существует ли файл
        if (!file_exists($zipFilePath)) {
            throw new Exception("Файл не найден: $zipFilePath");
        }

        // Получаем имя файла для скачивания
        $fileName = basename($zipFilePath);

        // Заголовки для скачивания файла
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip'); // MIME-тип для ZIP-архива
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($zipFilePath));

        // Читаем файл и выводим его содержимое в поток
        readfile($zipFilePath);
        // Удаляем ZIP-архив после скачивания
        if (!unlink($zipFilePath)) {
            error_log("Ошибка: Не удалось удалить ZIP-архив $zipFilePath");
        }

        exit;
    }
    /**
     * Удаляет временную папку и её содержимое.
     *
     * @param string $directory Путь к папке, которую нужно удалить.
     * @return void
     * @throws Exception Если директория не существует или её не удалось удалить.
     */
    public function deleteTempDirectory(string $directory): void {
        if (!is_dir($directory)) {
            throw new Exception("Директория не существует: $directory");
        }

        $files = array_diff(scandir($directory), ['.', '..']);

        foreach ($files as $file) {
            $filePath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($filePath)) {
                // Рекурсивно удаляем вложенные папки
                $this->deleteTempDirectory($filePath);
            } else {
                // Удаляем файл
                unlink($filePath);
            }
        }

        // Удаляем саму папку
        if (!rmdir($directory)) {
            throw new Exception("Не удалось удалить директорию: $directory");
        }
    }
    public function display($args, $request)
    {
        parent::display($args, $request);

        // Get the journal, press or preprint server id
        $contextId = Application::get()->getRequest()->getContext()->getId();

        // Use the path to determine which action
        // should be taken.
        $path = array_shift($args);
        switch ($path) {

            // Stream a CSV file for download
            case 'exportAll':
                //header('content-type: text/comma-separated-values');
                //header('content-disposition: attachment; filename=articles-' . date('Ymd') . '.xml');

                $submissions = $this->getAll($contextId);
                //$issues = $this->getAllIssues($contextId);
                //$this->export($submissions, 'php://output');

                break;

            // When no path is requested, display a list of submissions
            // to export and a button to run the `exportAll` path.
            case 'exportByIssue':
                $issueId = (int)$request->getUserVar('issueId');

                if ($issueId) {
                    //header('content-type: text/comma-separated-values');
                    //header('content-disposition: attachment; filename=issue-' . $issueId . '-' . date('Ymd') . '.xml');
                    try {
                        $context = $request->getContext();
                        $tempPath = $this->createTempDirectory('issue' . $issueId . '_');
                        $rsciJournal = new RsciJournal($context, $tempPath); // Добавить отображение нескольких журналов
                        $xmlStr = $rsciJournal->getXML($issueId);
                        //$submissions = $this->getByIssue($contextId, $issueId);

                        $filePath = $tempPath . '/' . $issueId . '-' . date('Ymd') . '.xml';
                        file_put_contents($filePath, $xmlStr);
                        $zipFile = $this->createZipInSameDirectory($tempPath);
                        $this->downloadZipArchive($zipFile);

                        $this->deleteTempDirectory($tempPath);
                    } catch (Exception $e) {
                        error_log("Ошибка: " . $e->getMessage());
                    }
                    //$this->export($submissions, 'php://output');
                } else {
                    // Если номер не выбран, вернуть на страницу с сообщением об ошибке
                    $request->redirect(null, 'index', null, ['error' => 'noIssueSelected']);
                }
                break;
            default:
                $templateMgr = TemplateManager::getManager($request);
                $issues= $this->getAllIssues($contextId);
                $templateMgr->assign([
                    'pageTitle' => __('plugins.importexport.rsciExport.name'),
                    'submissions' => $this->getAll($contextId),
                    'issues' => $issues,
                ]);

                $templateMgr->display(
                    $this->getTemplateResource('export.tpl')
                );
        }
    }

    public function executeCLI($scriptName, &$args)
    {
        $csvFile = array_shift($args);
        $contextId = array_shift($args);

        if (!$csvFile || !$contextId) {
            $this->usage('');
        }

        $submissions = $this->getAll($contextId);

        $this->export($submissions, $csvFile);
    }

    public function usage($scriptName)
    {
        echo __('plugins.importexport.rsciExport.cliUsage', [
            'scriptName' => $scriptName,
            'pluginName' => $this->getName()
        ]) . "\n";
    }

    /**
     * A helper method to get all published submissions for export
     *
     * @param int contextId Which journal, press or preprint server to get submissions for
     */
    public function getAll(int $contextId): LazyCollection
    {
        return Repo::submission()
            ->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->getMany();
    }
    public function getAllIssues(int $contextId): LazyCollection
    {
        return Repo::issue()
            ->getCollector()
            ->filterByContextIds([$contextId])
            ->getMany();
    }
    /**
     * A helper method to stream all published submissions
     * to a CSV file
     */
    public function export(LazyCollection $submissions, $filename)
    {
        $fp = fopen($filename, 'wt');
        fputcsv($fp, ['ID', 'Title']);

        /** @var Submission $submission */
        foreach ($submissions as $submission) {
            fputcsv(
                $fp,
                [
                    $submission->getId(),
                    $submission->getCurrentPublication()->getLocalizedFullTitle()
                ]
            );
        }

        fclose($fp);
    }

}

