<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\API\tests\Integration;

use Piwik\DataTable;
use Piwik\DataTable\Map;
use Piwik\Metrics;
use Piwik\Plugins\API\API;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Tests the percentage of the report total columns added by API.getProcessedReport, which is what
 * makes those percentages available to scheduled reports in every output format.
 *
 * @group API
 * @group Plugins
 */
class ProcessedReportRatioColumnsTest extends IntegrationTestCase
{
    /**
     * @var int
     */
    private $idSite;

    public function setUp(): void
    {
        parent::setUp();

        // the column labels and the percentage formatting are translated
        Fixture::loadAllTranslations();

        Fixture::createSuperUser(true);
        $this->idSite = Fixture::createWebsite('2015-01-01 00:00:00');

        // 3 visits from France, 1 from Germany, so the report totals are not a round number
        foreach (['fr', 'fr', 'fr', 'de'] as $index => $country) {
            $tracker = Fixture::getTracker($this->idSite, '2015-01-02 ' . (10 + $index) . ':00:00');
            $tracker->setCountry($country);
            $tracker->setIp('10.0.0.' . ($index + 1));
            Fixture::checkResponse($tracker->doTrackPageView('/page-' . $index));
        }

        $trackerDayTwo = Fixture::getTracker($this->idSite, '2015-01-03 10:00:00');
        $trackerDayTwo->setCountry('fr');
        Fixture::checkResponse($trackerDayTwo->doTrackPageView('/page-one'));
    }

    public function testEligibleMetricGetsARatioColumnRightAfterTheMetric(): void
    {
        $processed = $this->getProcessedReport('UserCountry', 'getCountry');
        $columns = $processed['columns'];

        self::assertArrayHasKey('nb_visits_report_ratio', $columns);
        self::assertSame('Visits (%)', $columns['nb_visits_report_ratio']);

        // the ratio column must directly follow the metric it belongs to
        $columnNames = array_keys($columns);
        $visitsPosition = array_search('nb_visits', $columnNames, true);
        self::assertSame('nb_visits_report_ratio', $columnNames[$visitsPosition + 1]);
    }

    public function testRatioValuesUseTheReportTotalAsDenominator(): void
    {
        $processed = $this->getProcessedReport('UserCountry', 'getCountry');

        /** @var DataTable $reportData */
        $reportData = $processed['reportData'];
        $totals = $reportData->getMetadata('totalsUnformatted');

        self::assertIsArray($totals);
        self::assertArrayHasKey('nb_visits', $totals);
        self::assertSame(4, (int) $totals['nb_visits']);

        $ratiosByLabel = [];
        foreach ($reportData->getRows() as $row) {
            $ratiosByLabel[$row->getColumn('label')] = $row->getColumn('nb_visits_report_ratio');

            // the value must be the same the HTML table visualization shows on hover
            self::assertSame(
                Metrics::formatReportRatio($row->getColumn('nb_visits'), $totals['nb_visits']),
                $row->getColumn('nb_visits_report_ratio')
            );
        }

        self::assertSame('75%', $ratiosByLabel['France']);
        self::assertSame('25%', $ratiosByLabel['Germany']);
    }

    public function testPercentageRateAndAverageMetricsDoNotGetARatioColumn(): void
    {
        $columns = $this->getProcessedReport('UserCountry', 'getCountry')['columns'];

        foreach (['bounce_rate', 'nb_actions_per_visit', 'avg_time_on_site'] as $metric) {
            self::assertArrayHasKey($metric, $columns, "$metric is expected to be part of the report");
            self::assertArrayNotHasKey($metric . Metrics::REPORT_RATIO_COLUMN_SUFFIX, $columns);
        }
    }

    public function testReportWithoutADimensionIsUnchanged(): void
    {
        // reports without a dimension have no report total to compare a row to
        $columns = $this->getProcessedReport('VisitsSummary', 'get')['columns'];

        foreach (array_keys($columns) as $columnName) {
            self::assertStringEndsNotWith(Metrics::REPORT_RATIO_COLUMN_SUFFIX, $columnName);
        }
    }

    public function testReportExcludingAMetricKeepsItsOwnPercentageColumnInstead(): void
    {
        // DevicePlugins.getPlugin computes '% Visits' against the visits of browsers plugins can be
        // detected for, so it opts out of the report total ratio
        $columns = $this->getProcessedReport('DevicePlugins', 'getPlugin')['columns'];

        self::assertArrayHasKey('nb_visits_percentage', $columns);
        self::assertArrayNotHasKey('nb_visits_report_ratio', $columns);
    }

    public function testMultiplePeriodsGetRatioColumnsPerPeriod(): void
    {
        $processed = $this->getProcessedReport('UserCountry', 'getCountry', 'day', '2015-01-02,2015-01-03');

        self::assertArrayHasKey('nb_visits_report_ratio', $processed['columns']);

        /** @var Map $reportData */
        $reportData = $processed['reportData'];
        self::assertInstanceOf(Map::class, $reportData);

        $tables = array_values($reportData->getDataTables());
        self::assertCount(2, $tables);

        // 3 of 4 visits on the first day, the single visit of the second day is 100% of that day
        self::assertSame('75%', $tables[0]->getFirstRow()->getColumn('nb_visits_report_ratio'));
        self::assertSame('100%', $tables[1]->getFirstRow()->getColumn('nb_visits_report_ratio'));
    }

    /**
     * @return array<string, mixed>
     */
    private function getProcessedReport(
        string $apiModule,
        string $apiAction,
        string $period = 'day',
        string $date = '2015-01-02'
    ): array {
        $result = API::getInstance()->getProcessedReport(
            $this->idSite,
            $period,
            $date,
            $apiModule,
            $apiAction
        );

        self::assertIsArray($result);
        return $result;
    }
}
