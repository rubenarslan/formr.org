<?php

/**
 * An R result is cached under the answers its expression can read
 * (RCallService::answersKey), so answering something else keeps it. An
 * expression naming the survey frame, or a run with its own R functions,
 * keys on every answer.
 */
class RCallServiceCacheKeyTest extends \PHPUnit\Framework\TestCase {

    private function key($expr, array $answers, $customR = '') {
        $study = (new ReflectionClass(SurveyStudy::class))->newInstanceWithoutConstructor();
        $study->id = 1;
        $study->name = 'diary';
        $run = $this->createMock(Run::class);
        $run->method('getCustomRFunctions')->willReturn($customR);
        $runSession = $this->createMock(RunSession::class);
        $runSession->method('getRun')->willReturn($run);
        $unitSession = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $unitSession->runSession = $runSession;
        $m = new ReflectionMethod(RCallService::class, 'answersKey');
        $m->setAccessible(true);
        return $m->invoke(new RCallService($unitSession, $study), $expr, $answers);
    }

    public function testOnlyTheNamedAnswersCount() {
        $this->assertSame(
            $this->key('q1 > 2', array('q1' => '3', 'q2' => 'a')),
            $this->key('q1 > 2', array('q1' => '3', 'q2' => 'b')),
            'another answer does not change the key'
        );
        $this->assertNotSame(
            $this->key('q1 > 2', array('q1' => '3')),
            $this->key('q1 > 2', array('q1' => '4'))
        );
        $this->assertNotSame(
            $this->key('aq1 > 2', array('q1' => '3', 'aq1' => '1')),
            $this->key('aq1 > 2', array('q1' => '3', 'aq1' => '2')),
            'names are whole words'
        );
    }

    public function testTheWholeFrameKeysOnEveryAnswer() {
        foreach (array('nrow(diary)', 'diary$q1', 'tail(diary, 1)$q1') as $expr) {
            $this->assertNotSame(
                $this->key($expr, array('q1' => '3', 'q2' => 'a')),
                $this->key($expr, array('q1' => '3', 'q2' => 'b')),
                $expr
            );
        }
        $this->assertSame(
            $this->key('diary_2 > 1', array('q2' => 'a')),
            $this->key('diary_2 > 1', array('q2' => 'b')),
            'another survey is not this frame'
        );
    }

    public function testRunFunctionsKeyOnEveryAnswer() {
        $this->assertNotSame(
            $this->key('my_score()', array('q2' => 'a'), 'my_score <- function() mean(diary$q2)'),
            $this->key('my_score()', array('q2' => 'b'), 'my_score <- function() mean(diary$q2)')
        );
    }
}
