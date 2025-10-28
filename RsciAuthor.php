<?php

namespace APP\plugins\importexport\rsciExport;
use SimpleXMLElement;
use PKP\author\Author;
use PKP\i18n\LocaleConversion;
class RsciAuthor

{
    private Author  $author;
    private SimpleXMLElement $authorElement;
    private int $num;

    /**
     * @param Author $author
     */
    public function __construct(SimpleXMLElement $authorElement, Author $author, int $num)
    {
        $this->author = $author;
        $this->authorElement = $authorElement;
        $this->num=$num;


    }
    public function toXML(){

        $this->authorElement->addAttribute('num', $this->num);

        $languages= array('ru', 'en');
        foreach ($languages as $lang)
        {

            $individElement=$this->authorElement->addChild('individInfo');
            $individElement->addAttribute('lang', strtoupper(LocaleConversion::get3LetterIsoFromLocale($lang)));
            $surname=$this->author->getData('familyName', $lang);
            if (!is_null($surname)) $individElement->addChild('surname', trim($surname));
            else $individElement->addChild('surname','None');
            $initials=$this->author->getData('givenName', $lang);
            $individElement->addChild('initials', $initials);
            $orgName=$this->author->getData('affiliation', $lang);
            $individElement->addChild('orgName', $orgName);
            $email=$this->author->getData('email');
            $individElement->addChild('email', $email);
            $countryCode = $this->author->getData('country');
            if (!empty($countryCode)) {
                // Указываем локаль для отображения страны
                $icuLocale = $lang === 'ru' ? 'ru' : 'en';
                $countryName = \Locale::getDisplayRegion('-' . $countryCode, $icuLocale) ?: $countryCode;
                // Специальное правило для Кыргызстана
                if (strtoupper($countryCode) === 'KG') {
                    $countryName = ($lang === 'ru') ? 'Кыргызская Республика' : 'Kyrgyz Republic';
                }
                $individElement->addChild('country', $countryName);
            }
        }
    }
}