<?php

namespace APP\plugins\importexport\rsciExport;

use SimpleXMLElement;
use APP\issue\Issue;
use APP\facades\Repo;
use PKP\i18n\Locale;
use APP\journal\Journal;
class RsciJournal
{
    // Свойства для хранения данных журнала
    public int $titleid;
    public string $issn;
    public string $eissn;
    public RsciJournalInfo $rsciJournalInfo;
    public array $issues;
    private Journal $context;
    private string $projectFolder;

    public function __construct(Journal $context, string $projectFolder) {
        $this->context = $context;
        $this->titleid = 79465093;
        $this->issn = $context->getData('printIssn');
        $this->eissn = $context->getData('onlineIssn');
        $this->issues = array();
        $this->projectFolder = $projectFolder;


    }



    public function getXML(int $issueId)
    {
        $xml = new SimpleXMLElement('<journal/>');

        $xml->addChild('titleid', $this->titleid);
        $xml->addChild('issn', $this->issn);
        $xml->addChild('eissn', $this->eissn);
        $issue=Repo::issue()->get($issueId);
        // Добавляем журналинфо
        $journalInfoElement = $xml->addChild('journalInfo');
        $this->rsciJournalInfo = new RsciJournalInfo($issue);
        $this->rsciJournalInfo->getXML($journalInfoElement);
        // Добавляем выпуска
        $issueElement = $xml->addChild('issue');
        $rsciIssue = new RsciIssue($issueElement, $issue, $this->projectFolder);
        $rsciIssue->getXML();
        return $xml->asXML();
    }
}