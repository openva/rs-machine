<?php

use PHPUnit\Framework\TestCase;
use RsMachine\Meetings;

/**
 * Which meetings in LIS's schedule are stored, and how.
 *
 * deploy/tests/data/lis_api/schedule_sample.json is entries from GetScheduleListAsync, as LIS
 * returned them (but for their attached files), one of each kind.
 */
class MeetingsTest extends TestCase
{
    private array $committees;

    /** @var array<int,array<string,mixed>> The sample's entries, by ScheduleID. */
    private array $schedule;

    protected function setUp(): void
    {
        // As they are in the database: note "Commerce and Labor," which LIS calls "Labor and
        // Commerce."
        $this->committees = Meetings::committeeIndex([
            ['id' => 2, 'lis_id' => 2, 'chamber' => 'house', 'name' => 'Appropriations', 'parent' => null],
            ['id' => 3, 'lis_id' => 14, 'chamber' => 'house', 'name' => 'Commerce and Labor', 'parent' => null],
            ['id' => 8, 'lis_id' => 11, 'chamber' => 'house', 'name' => 'General Laws', 'parent' => null],
            ['id' => 11, 'lis_id' => 18, 'chamber' => 'house', 'name' => 'Privileges and Elections', 'parent' => null],
            ['id' => 19, 'lis_id' => 5, 'chamber' => 'senate', 'name' => 'Finance', 'parent' => null],
            ['id' => 21, 'lis_id' => 7, 'chamber' => 'senate', 'name' => 'Local Government', 'parent' => null],
            ['id' => 23, 'lis_id' => 9, 'chamber' => 'senate', 'name' => 'Rehabilitation and Social Services', 'parent' => null],
            ['id' => 116, 'lis_id' => 2005, 'chamber' => 'house', 'name' => 'General Government', 'parent' => 'Appropriations'],
            ['id' => 117, 'lis_id' => 2006, 'chamber' => 'house', 'name' => 'Health & Human Resources', 'parent' => 'Appropriations'],
            ['id' => 122, 'lis_id' => 14001, 'chamber' => 'house', 'name' => '#1', 'parent' => 'Commerce and Labor'],
            ['id' => 124, 'lis_id' => 14003, 'chamber' => 'house', 'name' => 'Special Subcommittee on Energy', 'parent' => 'Commerce and Labor'],
        ]);

        $sample = json_decode(
            file_get_contents(__DIR__ . '/data/lis_api/schedule_sample.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($sample['Schedules'] as $entry) {
            $this->schedule[$entry['ScheduleID']] = $entry;
        }
    }

    private function meeting(int $schedule_id): ?array
    {
        return Meetings::fromSchedule($this->schedule[$schedule_id], $this->committees);
    }

    /**
     * LIS's dates carry a time of their own ("2026-03-13T12:00:00"), which isn't the meeting's.
     * (Appending the meeting's time to that, as this task once did, made a date strtotime()
     * couldn't read, so every meeting with a time was dated 1970, and skipped as past.)
     */
    public function testACommitteeMeetingIsStoredWithItsTime(): void
    {
        $this->assertSame([
            'date'         => '2026-03-13',
            'time'         => '18:00:00',
            'timedesc'     => null,
            'description'  => 'House Privileges and Elections Committee',
            'location'     => 'House Room 1, The Capitol',
            'committee_id' => 11,
        ], $this->meeting(3710));
    }

    public function testASubcommitteeIsFoundByItsNumberThoughItsNameDiffers(): void
    {
        $this->assertSame(124, $this->meeting(3576)['committee_id']);
        $this->assertSame(122, $this->meeting(3192)['committee_id']);
    }

    public function testAMeetingWithoutACommitteeNumberIsFoundByName(): void
    {
        $meeting = $this->meeting(3021);

        $this->assertSame(117, $meeting['committee_id']);
        $this->assertSame('House Appropriations - Health and Human Resources Subcommittee Other', $meeting['description']);
    }

    /**
     * A meeting held after another adjourns has no time, but says when on the first line of its
     * description, which is otherwise links.
     */
    public function testATimeGivenInTheDescriptionIsTheTimeDescription(): void
    {
        $this->assertSame([null, '15 minutes after adjournment of Subcommittee #1'],
            [$this->meeting(3576)['time'], $this->meeting(3576)['timedesc']]);
        $this->assertSame('Immediately upon adjournment of House Labor and Commerce', $this->meeting(3192)['timedesc']);
        $this->assertSame('Immediately upon recess of the House', $this->meeting(3695)['timedesc']);
        $this->assertSame('Immediately after the General Government and Capital Outlay Subcommittee', $this->meeting(3021)['timedesc']);
    }

    public function testANonBreakingSpaceIsASpaceAndALongTimeDescriptionFitsTheColumn(): void
    {
        $timedesc = $this->meeting(610)['timedesc'];

        $this->assertStringStartsWith('1/2 hour after adjournment of the House General Laws', $timedesc);
        $this->assertLessThanOrEqual(120, mb_strlen($timedesc));
    }

    /**
     * The Senate's dockets give the time as text, in the time field.
     */
    public function testATimeFieldThatIsntATimeIsTheTimeDescription(): void
    {
        $meeting = $this->meeting(3658);

        $this->assertNull($meeting['time']);
        $this->assertSame('Upon Adjournment of Commerce and Labor', $meeting['timedesc']);
        $this->assertSame('Senate Finance and Appropriations Docket', $meeting['description']);
        $this->assertSame(19, $meeting['committee_id']);
    }

    public function testATimeWrittenWithPeriodsIsATime(): void
    {
        $this->assertSame('08:00:00', $this->meeting(3604)['time']);
        $this->assertNull($this->meeting(3604)['timedesc']);
    }

    /**
     * @dataProvider clockTimes
     */
    public function testClockTimes(string $time, ?string $expected): void
    {
        $this->assertSame($expected, Meetings::clockTime($time));
    }

    public static function clockTimes(): array
    {
        return [
            ['8:30 AM', '08:30:00'],
            ['12:00 PM', '12:00:00'],
            ['12:15 AM', '00:15:00'],
            ['1:00 PM', '13:00:00'],
            ['9:30 a.m.', '09:30:00'],
            ['10 AM', '10:00:00'],
            ['', null],
            ['13:00 PM', null],
            ['Upon Adjournment of Resources Subcommittee', null],
        ];
    }

    public function testAMeetingWithNoTimeAtAllIsToBeAnnounced(): void
    {
        $this->assertSame([null, Meetings::TIME_UNKNOWN], Meetings::when('', '<a href="x">(Agenda)</a>'));
        $this->assertSame([null, Meetings::TIME_UNKNOWN], Meetings::when('TBA', ''));
    }

    public function testACancelledMeetingIsntStored(): void
    {
        $this->assertNull($this->meeting(3640));
    }

    /**
     * Its description links to the Senate's website, which once made it a Senate meeting.
     */
    public function testACommissionsMeetingIsntStored(): void
    {
        $this->assertNull($this->meeting(4233));
    }

    public function testAChambersAdjournmentIsntStored(): void
    {
        $this->assertNull($this->meeting(3841));
    }

    /**
     * The website gets each chamber's floor time from these, and leaves caucuses out itself.
     */
    public function testFloorSessionsAndCaucusesAreStored(): void
    {
        $this->assertSame('Senate Convenes Other', $this->meeting(3711)['description']);
        $this->assertSame('12:00:00', $this->meeting(3711)['time']);
        $this->assertSame('House Republican Caucus Caucus', $this->meeting(3986)['description']);
        $this->assertNull($this->meeting(3986)['committee_id']);
    }

    public function testAMeetingWithoutARoomHasNoLocation(): void
    {
        $meeting = Meetings::fromSchedule(
            ['RoomDescription' => null, 'IsCancelled' => false] + $this->schedule[3986],
            $this->committees
        );

        $this->assertSame('', $meeting['location']);
    }

    /**
     * A committee's subcommittee's name starts with the committee's, so the longest match wins,
     * whatever the order of the committees.
     */
    public function testTheMostSpecificCommitteeNameWins(): void
    {
        $entry = ['OwnerName' => 'House Appropriations - General Government Subcommittee',
            'ScheduleType' => 'Other', 'ScheduleDate' => '2026-02-13T12:00:00', 'ScheduleTime' => '8:00 AM'];

        $this->assertSame(116, Meetings::fromSchedule($entry, $this->committees)['committee_id']);

        $entry['OwnerName'] = 'House Appropriations Committee';
        $this->assertSame(2, Meetings::fromSchedule($entry, $this->committees)['committee_id']);

        $entry['OwnerName'] = 'House Appropriations Retreat';
        $this->assertSame(2, Meetings::fromSchedule($entry, $this->committees)['committee_id']);

        $entry['OwnerName'] = 'House Appropriationsville';
        $this->assertNull(Meetings::fromSchedule($entry, $this->committees)['committee_id']);
    }

    public function testAnEntryMissingItsDateIsntStored(): void
    {
        $entry = $this->schedule[3710];
        unset($entry['ScheduleDate']);

        $this->assertNull(Meetings::fromSchedule($entry, $this->committees));
    }

    private function row(array $fields): array
    {
        return $fields + [
            'date'         => '2026-01-20',
            'time'         => '08:30:00',
            'timedesc'     => null,
            'description'  => 'House Finance Committee',
            'location'     => 'House Room 1, The Capitol',
            'committee_id' => 9,
        ];
    }

    /**
     * Stored rows come back from the database as strings ("9", "08:30:00").
     */
    public function testAMeetingAlreadyStoredIsLeftAlone(): void
    {
        $stored = [$this->row(['id' => '5', 'committee_id' => '9'])];

        $this->assertSame(['insert' => [], 'delete' => []],
            Meetings::changes([$this->row([])], $stored, '2026-01-14'));
    }

    public function testANewMeetingIsAdded(): void
    {
        $changes = Meetings::changes([$this->row([])], [], '2026-01-14');

        $this->assertSame([$this->row([])], $changes['insert']);
        $this->assertSame([], $changes['delete']);
    }

    public function testARescheduledMeetingIsReplaced(): void
    {
        $stored = [$this->row(['id' => 5])];
        $changes = Meetings::changes([$this->row(['time' => '10:00:00'])], $stored, '2026-01-14');

        $this->assertSame([$this->row(['time' => '10:00:00'])], $changes['insert']);
        $this->assertSame([5], $changes['delete']);
    }

    public function testACancelledMeetingIsRemoved(): void
    {
        $this->assertSame(['insert' => [], 'delete' => [5]],
            Meetings::changes([], [$this->row(['id' => 5])], '2026-01-14'));
    }

    /**
     * Meetings with only a description of their time (and so no time) included, which SQL's
     * `time = time` never matches.
     */
    public function testDuplicatesAreRemoved(): void
    {
        $approximate = ['time' => null, 'timedesc' => 'Upon adjournment of the House'];
        $stored = [
            $this->row(['id' => 5]),
            $this->row(['id' => 6]),
            $this->row(['id' => 7] + $approximate),
            $this->row(['id' => 8] + $approximate),
        ];
        $scheduled = [$this->row([]), $this->row([]), $this->row($approximate)];

        $this->assertSame(['insert' => [], 'delete' => [6, 8]],
            Meetings::changes($scheduled, $stored, '2026-01-14'));
    }

    /**
     * The schedule goes back to 2022; past meetings are neither added nor removed.
     */
    public function testPastMeetingsAreLeftAlone(): void
    {
        $changes = Meetings::changes(
            [$this->row(['date' => '2026-01-13'])],
            [$this->row(['id' => 5, 'date' => '2026-01-12'])],
            '2026-01-14'
        );

        $this->assertSame(['insert' => [], 'delete' => []], $changes);
    }

    public function testTodaysMeetingsAreKept(): void
    {
        $stored = [$this->row(['id' => 5, 'date' => '2026-01-14'])];

        $this->assertSame(['insert' => [], 'delete' => []],
            Meetings::changes([$this->row(['date' => '2026-01-14'])], $stored, '2026-01-14'));
    }
}
