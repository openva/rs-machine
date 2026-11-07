<?php

use PHPUnit\Framework\TestCase;
use RsMachine\PlaceNames;

/**
 * RsMachine\PlaceNames: finding the counties and cities that a bill names, without GPT where it
 * can be done exactly. None of these need a database.
 */
class PlaceNamesTest extends TestCase
{
    /**
     * The gazetteer's names, which are the same for counties and cities that share a name.
     */
    private function localities(): array
    {
        $counties = ['Fairfax County', 'Richmond County', 'Franklin County', 'Roanoke County', 'Henrico County',
            'James City County', 'Charles City County', 'King and Queen County', 'Prince William County'];
        $cities = ['Fairfax', 'Richmond', 'Franklin', 'Roanoke', 'Manassas', 'Manassas Park', 'Norfolk',
            'Virginia Beach', 'Newport News', 'Colonial Heights', 'Charlottesville'];

        return array_merge(
            array_map(fn ($name) => ['name' => $name, 'county' => true], $counties),
            array_map(fn ($name) => ['name' => $name, 'county' => false], $cities)
        );
    }

    private function find(string $text): array
    {
        $result = PlaceNames::fromText($text, $this->localities());
        sort($result['places']);
        return $result;
    }

    public function testACountyIsFoundByEitherOfItsNames(): void
    {
        $this->assertSame(['places' => ['Fairfax County'], 'settled' => true],
            $this->find('Sales tax in Fairfax County.'));
        $this->assertSame(['places' => ['Henrico County'], 'settled' => true],
            $this->find('Authorizes the County of Henrico to levy a tax.'));
    }

    public function testACityIsFoundByEitherOfItsNames(): void
    {
        $this->assertSame(['places' => ['Norfolk'], 'settled' => true],
            $this->find('Authorizes the City of Norfolk to issue bonds.'));
        $this->assertSame(['places' => ['Charlottesville'], 'settled' => true],
            $this->find('Charlottesville City schools.'));
    }

    /**
     * A county and a city that share a name are two places, told apart by what they're called.
     */
    public function testACountyAndACityWithTheSameNameAreToldApart(): void
    {
        $this->assertSame(['Richmond County'], $this->find('Richmond County')['places']);
        $this->assertSame(['Richmond'], $this->find('the City of Richmond')['places']);
        $this->assertSame(['Fairfax County'], $this->find('Fairfax County')['places']);
        $this->assertSame(['Fairfax'], $this->find('City of Fairfax')['places']);
        $this->assertSame(['Fairfax', 'Fairfax County'], $this->find('City of Fairfax and Fairfax County')['places']);
    }

    /**
     * "City of Manassas Park" begins with "City of Manassas."
     */
    public function testALongerNameIsntAlsoFoundAsAShorterOne(): void
    {
        $this->assertSame(['Manassas Park'], $this->find('City of Manassas Park')['places']);
        $this->assertSame(['Manassas', 'Manassas Park'], $this->find('City of Manassas and City of Manassas Park')['places']);
    }

    /**
     * "James City County" and "Charles City County" contain "City."
     */
    public function testCountiesWithCityInTheirNamesAreCounties(): void
    {
        $this->assertSame(['places' => ['James City County'], 'settled' => true], $this->find('James City County'));
        $this->assertSame(['places' => ['Charles City County'], 'settled' => true], $this->find('County of Charles City'));
    }

    public function testNamesWithSeveralWordsAndOddSpacingAreFound(): void
    {
        $this->assertSame(['King and Queen County'], $this->find("King and\nQueen   County")['places']);
        $this->assertSame(['Virginia Beach'], $this->find('city of virginia beach')['places']);
    }

    public function testABareNameIsNotAPlace(): void
    {
        // Richmond could be the city, the county, or Richmond, Indiana.
        $this->assertSame(['places' => [], 'settled' => true], $this->find('The Richmond Times-Dispatch and Norfolk Southern.'));
    }

    public function testATextThatNamesNoPlacesIsSettled(): void
    {
        $this->assertSame(['places' => [], 'settled' => true], $this->find('Relating to the sale of fireworks.'));
    }

    public function testAPlaceIsFoundOnceHoweverOftenItIsNamed(): void
    {
        $this->assertSame(['Fairfax County'], $this->find('Fairfax County, Fairfax County, and County of Fairfax')['places']);
    }

    /**
     * What exact matching can't handle is left for GPT.
     */
    public function testAListOfCountiesIsNotSettled(): void
    {
        $result = $this->find('The Counties of Henrico and Chesterfield.');
        $this->assertFalse($result['settled']);
    }

    public function testATownIsNotSettled(): void
    {
        $this->assertFalse($this->find('The Town of Vienna.')['settled']);
        $this->assertFalse($this->find('The Towns of Vienna and Herndon.')['settled']);
    }

    public function testAPlaceWeDontKnowIsNotSettled(): void
    {
        $this->assertFalse($this->find('The Loudoun County Board.')['settled'], 'Loudoun isn\'t in the list');
        $this->assertFalse($this->find('The Prince George County Board.')['settled']);
    }

    public function testWhatIsFoundIsKeptEvenWhenTheTextIsNotSettled(): void
    {
        $result = $this->find('Fairfax County and the Counties of Henrico and Chesterfield.');
        $this->assertSame(['Fairfax County'], $result['places']);
        $this->assertFalse($result['settled']);
    }

    public function testACapitalizedNameWithCountyOrCityAfterItButNotKnownIsNotSettled(): void
    {
        $this->assertFalse($this->find('Salem City')['settled'], 'Salem isn\'t in this list');
    }

    /**
     * A bill's text as LIS gives it: an enactment clause, then each section (a "catchln" heading, and
     * "section" paragraphs), in which added text is in <ins> or <em class="new"> and struck text is in <s>.
     */
    private function amendment(string $body): string
    {
        return '<p class=enactstm>Be it enacted by the General Assembly of Virginia:</p>'
            . '<p class=enactcls>1. That &sect;&nbsp;<a href="#">15.2-2304</a> is amended and reenacted as follows:</p>'
            . '<p class=catchln>&sect;&nbsp;<a href="#">15.2-2304</a>. Affordable housing; zoning.</p>'
            . $body;
    }

    /**
     * Staunton is added to a list that already names Charlottesville, Richmond, and Virginia Beach.
     * Only Staunton is what the bill changes.
     */
    public function testAPlaceAddedToAListIsFoundButThePlacesAlreadyInItAreNot(): void
    {
        $html = $this->amendment('<p class=section>The localities are the City of Charlottesville, the City of Richmond, '
            . 'the City of Virginia Beach, <ins>and the City of Staunton</ins>.</p>');
        $text = PlaceNames::changedLanguage($html);

        $this->assertStringContainsString('Staunton', $text);
        $this->assertStringNotContainsString('Charlottesville', $text);
        $this->assertStringNotContainsString('Richmond', $text);
        $this->assertStringNotContainsString('Virginia Beach', $text);
    }

    public function testNewTextMarkedAsNewIsFoundAsWellAsInserted(): void
    {
        $html = $this->amendment('<p class=section>The City of Norfolk and <em class="new">the City of Salem</em> may.</p>');
        $text = PlaceNames::changedLanguage($html);
        $this->assertStringContainsString('the City of Salem', $text);
        $this->assertStringNotContainsString('Norfolk', $text);
    }

    /**
     * A place that a bill takes out of a law is a place that it changes, too.
     */
    public function testAPlaceStruckFromALawIsFound(): void
    {
        $html = $this->amendment('<p class=section>The Counties of Albemarle, <s>and Loudoun,</s> and the City of Norfolk.</p>');
        $text = PlaceNames::changedLanguage($html);
        $this->assertStringContainsString('Loudoun', $text);
        $this->assertStringNotContainsString('Albemarle', $text);
        $this->assertStringNotContainsString('Norfolk', $text);
    }

    /**
     * A new section has nothing marked in it, since all of it is new.
     */
    public function testEverythingInANewSectionIsFound(): void
    {
        $html = '<p class=enactcls>1. That the Code of Virginia is amended by adding a section numbered 36-140.02 as follows:</p>'
            . '<p class=catchln>&sect; 36-140.02. Housing grants.</p>'
            . '<p class=section>Grants shall go to Fairfax County, the City of Norfolk, and the City of Richmond.</p>';
        $text = PlaceNames::changedLanguage($html);
        $this->assertStringContainsString('Fairfax County', $text);
        $this->assertStringContainsString('City of Norfolk', $text);
        $this->assertStringContainsString('City of Richmond', $text);
    }

    /**
     * One bill that amends a section (naming places that it doesn't change), adds another, and has an
     * enactment clause of its own.
     */
    public function testEachSectionOfABillIsTakenOnItsOwn(): void
    {
        $html = '<p class=enactcls>1. That &sect; 1-1 is amended and reenacted and that the Code is amended by adding a section numbered 1-2 as follows:</p>'
            . '<p class=catchln>&sect; 1-1. Old section.</p>'
            . '<p class=section>The City of Richmond and Henrico County <ins>and Roanoke County</ins> may.</p>'
            . '<p class=catchln><ins>&sect; 1-2. New section.</ins></p>'
            . '<p class=section>The City of Norfolk may.</p>'
            . '<p class=catchln>&sect; 1-3. Another new section.</p>'
            . '<p class=section>The City of Salem may.</p>'
            . '<p class=enactcls>2. That the work group shall include Prince William County.</p>';
        $text = PlaceNames::changedLanguage($html);

        $this->assertStringContainsString('Roanoke County', $text, 'Added to an amended section');
        $this->assertStringNotContainsString('Henrico', $text, 'Already in the amended section');
        $this->assertStringNotContainsString('City of Richmond', $text, 'Already in the amended section');
        $this->assertStringContainsString('City of Salem', $text, 'A new section with nothing marked');
        $this->assertStringContainsString('Prince William County', $text, 'An enactment clause');
    }

    /**
     * A resolution has no markup, and no sections: all of it is its language.
     */
    public function testAResolutionIsTakenWhole(): void
    {
        $html = '<center>HOUSE JOINT RESOLUTION NO. 14</center><p>WHEREAS, the Wolf Creek Cherokee live in Wythe County and the City of Richmond.</p>';
        $text = PlaceNames::changedLanguage($html);
        $this->assertStringContainsString('Wythe County', $text);
        $this->assertStringContainsString('City of Richmond', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    /**
     * Older bills don't have the section markup, but still mark what they change.
     */
    public function testATextWithMarkedPassagesButNoSectionsIsTakenByItsMarkedPassages(): void
    {
        $text = PlaceNames::changedLanguage('<p>A BILL to amend. The City of Richmond may <ins>and Salem City</ins> do so.</p>');
        $this->assertStringContainsString('Salem City', $text);
        $this->assertStringNotContainsString('Richmond', $text);
    }

    /**
     * LIS puts a non-breaking space between the words of a name, as in "Fairfax&nbsp;County".
     */
    public function testANameWithANonBreakingSpaceIsMatched(): void
    {
        $text = PlaceNames::changedLanguage('<p class=enactcls>1. That the City&nbsp;of&nbsp;Norfolk and Fairfax&nbsp;County shall report.</p>');
        $result = PlaceNames::fromText($text, $this->localities());
        sort($result['places']);
        $this->assertSame(['Fairfax County', 'Norfolk'], $result['places']);
        $this->assertTrue($result['settled']);
    }

    public function testTheSummaryIsAddedToTheChangedLanguage(): void
    {
        $bill = (object) [
            'summary' => 'Adds Fairfax County to the casino list.',
            'full_text' => $this->amendment('<p class=section>The City of Richmond <ins>and Norfolk City</ins>.</p>'),
        ];
        $text = PlaceNames::billText($bill);
        $this->assertStringContainsString('Adds Fairfax County to the casino list.', $text);
        $this->assertStringContainsString('Norfolk City', $text);
        $this->assertStringNotContainsString('City of Richmond', $text);
        $this->assertSame('Only a summary.', trim(PlaceNames::billText((object) ['summary' => 'Only a summary.'])));
    }

    /**
     * Some bills are 700,000 characters, more than a model accepts.
     */
    public function testALongTextIsCutForTheModelButAShortOneIsNot(): void
    {
        $short = str_repeat('x', PlaceNames::MODEL_MAX_CHARS);
        $this->assertSame($short, PlaceNames::forModel($short));

        $cut = PlaceNames::forModel(str_repeat('é', 700000));   // Multibyte, to be cut by characters
        $this->assertSame(PlaceNames::MODEL_MAX_CHARS, mb_strlen($cut));
        $this->assertSame(1, preg_match('//u', $cut), 'Still valid UTF-8');
    }

    public function testASummaryFromTheDatabaseIsCleanedUp(): void
    {
        $text = PlaceNames::billText((object) ['summary' => 'Adds <i>Fairfax&nbsp;County</i> &amp; Norfolk.', 'full_text' => '']);
        $result = PlaceNames::fromText($text, $this->localities());
        $this->assertSame(['Fairfax County'], $result['places']);
        $this->assertStringContainsString('& Norfolk', $text);
        $this->assertStringNotContainsString('<i>', $text);
    }

    public function testAnAnswerIsSplitIntoPlaces(): void
    {
        $this->assertSame(
            ['County of Fairfax', 'City of Norfolk', 'Town of Vienna'],
            PlaceNames::fromAnswer('County of Fairfax, City of Norfolk, Town of Vienna.')
        );
        $this->assertSame(['City of Norfolk'], PlaceNames::fromAnswer('"City of Norfolk", City of Norfolk'), 'Quotes and duplicates');
    }

    /**
     * It's asked to stay silent when there are no places, but sometimes explains instead.
     */
    public function testAnAnswerThatSaysThereAreNoPlacesIsEmpty(): void
    {
        $this->assertSame([], PlaceNames::fromAnswer(''));
        $this->assertSame([], PlaceNames::fromAnswer('   '));
        $this->assertSame([], PlaceNames::fromAnswer('No specific places are mentioned.'));
        $this->assertSame([], PlaceNames::fromAnswer('There are no places mentioned in the provided text.'));
    }

    public function testPlacesAreLookedUpByTheirKindOfName(): void
    {
        $this->assertSame(['name' => 'Fairfax County', 'county' => true], PlaceNames::lookup('County of Fairfax'));
        $this->assertSame(['name' => 'Fairfax County', 'county' => true], PlaceNames::lookup('Fairfax County'));
        $this->assertSame(['name' => 'King and Queen County', 'county' => true], PlaceNames::lookup('County of King and Queen'));
        $this->assertSame(['name' => 'Norfolk', 'county' => false], PlaceNames::lookup('City of Norfolk'));
        $this->assertSame(['name' => 'Scottsville', 'county' => false], PlaceNames::lookup('Town of Scottsville'));
    }

    /**
     * The old code reused the previous place's query for a name like this, and so tagged the bill
     * with the wrong place.
     */
    public function testAPlaceThatCantBeToldApartIsNotLookedUp(): void
    {
        $this->assertNull(PlaceNames::lookup('Richmond'));
        $this->assertNull(PlaceNames::lookup('Virginia'));
        $this->assertNull(PlaceNames::lookup(''));
    }
}
