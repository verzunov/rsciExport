<?php

namespace APP\plugins\importexport\rsciExport;
use SimpleXMLElement;
use APP\issue\Issue;
class RsciJournalInfo
{

    public string $title;

    public function __construct(Issue $issue)
    {
        $this->title = $issue->getTitle('ru');
    }

    public function getXML(SimpleXMLElement $journalInfo)
    {
        $journalInfo->addChild('title', $this->title);
    }
}