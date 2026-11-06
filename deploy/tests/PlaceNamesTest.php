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

    public function testBillTextIsTheSummaryAndTheChangedText(): void
    {
        $bill = (object) [
            'summary' => 'Fireworks.',
            'full_text' => '<p>Existing law about Fairfax County.</p>',
            'changes' => [(object) ['text' => 'Norfolk'], (object) ['text' => 'Hampton']],
        ];
        $text = PlaceNames::billText($bill);
        $this->assertStringContainsString('Fireworks.', $text);
        $this->assertStringContainsString("Norfolk\nHampton\n", $text);
        $this->assertStringNotContainsString('Fairfax', $text, 'Existing law isn\'t what the bill is about');
    }

    public function testBillTextIsTheWholeTextWhenNothingIsChanged(): void
    {
        $text = PlaceNames::billText((object) ['summary' => 'Fireworks.', 'full_text' => '<p>Fairfax County.</p>', 'changes' => []]);
        $this->assertSame('Fireworks. Fairfax County.', $text, 'Without tags');
        $this->assertSame('Only a summary. ', PlaceNames::billText((object) ['summary' => 'Only a summary.']));
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
