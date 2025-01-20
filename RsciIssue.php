<?php

namespace APP\plugins\importexport\rsciExport;
use SimpleXMLElement;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\publication\Publication;
class RsciIssue
{
    private int $volume;
    private string $number;
    private int $dateUni;
    private array $issTitle;
    private SimpleXMLElement $issueElement;
    private Issue $issue;
    private string $projectFolder;

    public function __construct(SimpleXMLElement $issueElement, Issue $issue, string $projectFolder)
    {
        $this->issue = $issue;
        $this->issueElement = $issueElement;
        $this->volume = (int)$issue->getVolume();
        $this->number = $issue->getNumber();
        $this->dateUni= $issue->getYear();
        $this->issTitle =  $issue->getData('title');
        $this->projectFolder = $projectFolder;
    }
    public function getXML(){
        $this->issueElement->addChild('volume', $this->volume);
        $this->issueElement->addChild('number', $this->number);
        $this->issueElement->addChild('dateUni', $this->dateUni);
        $arcticlesElement = $this->issueElement->addChild('articles');
        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$this->issue->getJournalId()])
            ->filterByIssueIds([$this->issue->getId()])
            ->getMany()
            ->toArray();
        $submissionsArray = [];
        foreach ($submissions as $submission) {
            array_push($submissionsArray, $submission->getId());
        }
        $publications = Repo::publication()
            ->getCollector()
            ->filterByContextIds([$this->issue->getJournalId()])
            ->filterBySubmissionIds($submissionsArray)
            ->getMany()
            ->toArray();
        usort($publications, function ($a1, $a2) {
            if ($a1->getStartingPage() == $a2->getStartingPage()) {
                return 0;
            }
            return ($a1->getStartingPage() < $a2->getStartingPage()) ? -1 : 1;
        });
        foreach ($publications as $article){
            $arcticleElement = $arcticlesElement->addChild('article');
            $rsciArticle = new RsciArticle($arcticleElement, $article, $this->projectFolder);
            $rsciArticle->getXML();
        }

    }
}