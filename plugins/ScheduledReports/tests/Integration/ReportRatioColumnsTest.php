<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\ScheduledReports\tests\Integration;

use Piwik\Date;
use Piwik\Plugins\API\API as APIPlugin;
use Piwik\Plugins\ScheduledReports\API as APIScheduledReports;
use Piwik\Plugins\ScheduledReports\ScheduledReports;
use Piwik\ReportRenderer;
use Piwik\Scheduler\Schedule\Schedule;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Tests the percentage of the report total columns end to end, ie. as they appear in a generated
 * scheduled report.
 *
 * @group Plugins
 * @group ScheduledReports
 */
class ReportRatioColumnsTest extends IntegrationTestCase
{
    /**
     * @var int
     */
    private $idSite;

    /**
     * @var string
     */
    private $date = '2015-01-02';

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();
        Fixture::createSuperUser(true);

        $this->idSite = Fixture::createWebsite('2015-01-01 00:00:00');

        // 3 visits from France, 1 from Germany
        foreach (['fr', 'fr', 'fr', 'de'] as $index => $country) {
            $tracker = Fixture::getTracker($this->idSite, $this->date . ' ' . (10 + $index) . ':00:00');
            $tracker->setCountry($country);
            $tracker->setIp('10.0.0.' . ($index + 1));
            Fixture::checkResponse($tracker->doTrackPageView('/page-' . $index));
        }
    }

    public function testCsvReportUsesTheColumnTranslationAsHeader(): void
    {
        $report = $this->generateReport(ReportRenderer::CSV_FORMAT, 'UserCountry_getCountry');

        self::assertStringContainsString('Visits (%)', $report);
        // the internal column name must not leak into the export
        self::assertStringNotContainsString('nb_visits_report_ratio', $report);

        [, $headerLine] = explode("\n", $report);
        $header = explode(',', $headerLine);

        // the percentage is shown directly after the metric it belongs to
        self::assertSame('Visits (%)', $header[array_search('nb_visits', $header, true) + 1]);
        self::assertStringContainsString('France,3,75%', $report);
        self::assertStringContainsString('Germany,1,25%', $report);
    }

    public function testTsvReportUsesTheColumnTranslationAsHeader(): void
    {
        $report = $this->generateReport(ReportRenderer::TSV_FORMAT, 'UserCountry_getCountry');

        self::assertStringContainsString('Visits (%)', $report);
        self::assertStringNotContainsString('nb_visits_report_ratio', $report);
        self::assertStringContainsString("France\t3\t75%", $report);
    }

    public function testHtmlReportShowsThePercentageColumn(): void
    {
        $report = $this->generateReport(ReportRenderer::HTML_FORMAT, 'UserCountry_getCountry');

        self::assertStringContainsString('Visits (%)', $report);
        self::assertStringContainsString('75%', $report);
    }

    public function testRateMetricsDoNotGetAPercentageColumn(): void
    {
        $report = $this->generateReport(ReportRenderer::CSV_FORMAT, 'UserCountry_getCountry');

        self::assertStringContainsString('bounce_rate', $report);
        self::assertStringNotContainsString('bounce_rate_report_ratio', $report);
        self::assertStringNotContainsString('Bounce Rate (%)', $report);
    }

    public function testReportOwnVisitsPercentageIsReplacedByThePercentageOfTheReportTotal(): void
    {
        $report = $this->generateReport(ReportRenderer::CSV_FORMAT, 'VisitorInterest_getNumberOfVisitsByVisitCount');

        self::assertStringContainsString('Visits (%)', $report);
        // the report computes a visits percentage itself, both columns would be redundant
        self::assertStringNotContainsString('nb_visits_percentage', $report);

        // the metric is still part of the API output though
        $processed = APIPlugin::getInstance()->getProcessedReport(
            $this->idSite,
            'day',
            $this->date,
            'VisitorInterest',
            'getNumberOfVisitsByVisitCount'
        );

        self::assertArrayHasKey('nb_visits_percentage', $processed['columns']);
    }

    private function generateReport(string $format, string $reportId): string
    {
        $idReport = APIScheduledReports::getInstance()->addReport(
            $this->idSite,
            'ratio columns',
            Schedule::PERIOD_DAY,
            0,
            ScheduledReports::EMAIL_TYPE,
            $format,
            array($reportId),
            array(ScheduledReports::DISPLAY_FORMAT_PARAMETER => ScheduledReports::DISPLAY_FORMAT_TABLES_ONLY)
        );

        ob_start();
        $report = APIScheduledReports::getInstance()->generateReport(
            $idReport,
            Date::factory($this->date)->toString(),
            'en',
            APIScheduledReports::OUTPUT_RETURN
        );
        ob_end_clean();

        self::assertIsString($report);
        return $report;
    }
}
