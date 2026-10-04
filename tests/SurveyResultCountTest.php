<?php
use PHPUnit\Framework\TestCase;

/**
 * SurveyStudy::getResultCount answers an unscoped count from the study's
 * rollup (or its per-object cache). The admin results page always passes
 * the filter fields, blank when nothing is filtered — which used to count
 * live, for a v2 study grouping every display row of the study on each
 * page view.
 */
class SurveyResultCountTest extends TestCase
{
    public function testBlankFiltersCountLikeNoFilter()
    {
        $study = new class extends SurveyStudy {
            public $live = 0;
            public function __construct() {}
            public function getResultCountLive($run_id = null, $filter = array()) {
                $this->live++;
                return array('finished' => 0, 'begun' => 0, 'testers' => 0, 'real_users' => 0);
            }
        };
        $cached = array('finished' => 1, 'begun' => 2, 'testers' => 3, 'real_users' => 4);
        $count = new ReflectionProperty(SurveyStudy::class, 'result_count');
        $count->setAccessible(true);
        $count->setValue($study, $cached);
        $this->assertSame($cached, $study->getResultCount(null, array('session' => '', 'results' => '')));
        $this->assertSame(0, $study->live);
        $study->getResultCount(null, array('session' => 'abc', 'results' => ''));
        $this->assertSame(1, $study->live, 'a filter that filters counts live');
    }
}
