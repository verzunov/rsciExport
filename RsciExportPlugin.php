<?php
/**
 * @file RsciExportPlugin.inc.php
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







// Автозагрузка: composer или ручное подключение
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    foreach (['RsciJournal.php', 'RsciIssue.php', 'RsciArticle.php', 'RsciAuthor.php', 'RsciJournalInfo.php'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (is_file($path)) {
            require_once $path;
        }
    }
}




class RsciExportPlugin extends ImportExportPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success) {
            $this->addLocaleData();
        }

        return $success;
    }

    public function getName(): string
    {
        // Должно совпадать с названием папки плагина (rsciExport)
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

        $context = $request->getContext();
        if (!$context) {
            throw new \RuntimeException('No context found');
        }
        $contextId = $context->getId();

        $op = array_shift($args) ?: null;

        switch ($op) {
            case 'exportByIssue': {
                $issueId = (int) $request->getUserVar('issueId');
                if (!$issueId) {
                    // Номер не выбран — редирект обратно
                    $request->redirect(
                        null, 'management', 'importexport',
                        ['plugin', $this->getName()]
                    );
                    return;
                }

                try {
                    $tempPath   = $this->createTempDirectory('issue' . $issueId . '_');
                    $rsciJournal = new \APP\plugins\importexport\rsciExport\RsciJournal($context, $tempPath);
                    $xmlStr     = $rsciJournal->getXML($issueId);

                    $filePath = $tempPath . '/' . $issueId . '-' . date('Ymd') . '.xml';
                    file_put_contents($filePath, $xmlStr);

                    $zipFile = $this->createZipInSameDirectory($tempPath);

                    // путь к корню приложения
                    $appBase = realpath(__DIR__ . '/../../..');

                    // публичная папка journals/{contextId}/exports
                    $publicExportDir = $appBase
                        . DIRECTORY_SEPARATOR . 'public'
                        . DIRECTORY_SEPARATOR . 'journals'
                        . DIRECTORY_SEPARATOR . $contextId
                        . DIRECTORY_SEPARATOR . 'exports';

                    if (!is_dir($publicExportDir)) {
                        if (!mkdir($publicExportDir, 0775, true) && !is_dir($publicExportDir)) {
                            throw new \RuntimeException('Не удалось создать директорию для экспорта: ' . $publicExportDir);
                        }
                    }

                    $targetFile = $publicExportDir . DIRECTORY_SEPARATOR . basename($zipFile);
                    if (!@rename($zipFile, $targetFile)) {
                        // если rename не удаётся, попытать copy + unlink
                        if (!@copy($zipFile, $targetFile)) {
                            throw new \RuntimeException('Не удалось переместить архив в публичную папку');
                        }
                        @unlink($zipFile);
                    }

                    // удаляем временную папку
                    $this->deleteTempDirectory($tempPath);

                    // редирект на интерфейс плагина с параметрами
                    $fileName = basename($targetFile);
                    $request->redirect(
                        null, 'management', 'importexport',
                        ['plugin', $this->getName()],
                        ['export' => 1, 'file' => $fileName]
                    );
                    return;
                } catch (\Throwable $e) {
                    error_log('RSCI export error: ' . $e->getMessage());
                    $request->redirect(
                        null, 'management', 'importexport',
                        ['plugin', $this->getName()]
                    );
                    return;
                }
            }

            default: {
                // default страница плагина
                $router = $request->getRouter();
                $exportByIssueUrl = $router->url(
                    $request,
                    null,
                    'management',
                    'importexport',
                    ['plugin', $this->getName(), 'exportByIssue']
                );

                $templateMgr = TemplateManager::getManager($request);

                // проверка, вернулись ли после экспорта
                $exportOk = $request->getUserVar('export');
                $exportFile = $request->getUserVar('file');

                if ($exportOk && $exportFile) {
                    $downloadUrl = $request->getBaseUrl()
                        . '/public/journals/' . $contextId
                        . '/exports/' . rawurlencode($exportFile);
                    $templateMgr->assign('exportSuccess', true);
                    $templateMgr->assign('downloadUrl', $downloadUrl);
                    $templateMgr->assign('exportFileName', $exportFile);
                }

                $templateMgr->assign([
                    'pageTitle'        => __('plugins.importexport.rsciExport.name'),
                    'issues'           => $this->getAllIssues($contextId),
                    'exportByIssueUrl' => $exportByIssueUrl,
                ]);

                $templateMgr->display($this->getTemplateResource('export.tpl'));
                return;
            }
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

