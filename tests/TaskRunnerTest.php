<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\TaskRunner;
use ShipIt\TerminalUI;

class TaskRunnerTest extends TestCase
{
    private TaskRunner $runner;
    private array $executed;

    protected function setUp(): void
    {
        $ui = $this->createStub(TerminalUI::class);
        $this->runner = new TaskRunner($ui);
        $this->executed = [];
    }

    public function testAddTaskAndRun(): void
    {
        $this->runner->addTask('test_task', function () {
            $this->executed[] = 'task';
        });

        $this->runner->run(['test_task'], [], [], false);

        $this->assertSame(['task'], $this->executed);
    }

    public function testPreAndPostHooks(): void
    {
        $this->runner->addTask('test_task', function () {
            $this->executed[] = 'task';
        });

        $this->runner->addPreHook('test_task', function () {
            $this->executed[] = 'pre';
        });

        $this->runner->addPostHook('test_task', function () {
            $this->executed[] = 'post';
        });

        $this->runner->run(['test_task'], [], [], false);

        $this->assertSame(['pre', 'task', 'post'], $this->executed);
    }

    public function testMergeRunOrderPrependAppend(): void
    {
        $base = ['task2', 'task3'];
        $rules = [
            'prepend' => ['task1'],
            'append' => ['task4']
        ];

        $merged = $this->runner->mergeRunOrder($base, $rules);

        $this->assertSame(['task1', 'task2', 'task3', 'task4'], $merged);
    }

    public function testMergeRunOrderBeforeAfter(): void
    {
        $base = ['task1', 'task3'];
        $rules = [
            'before' => [
                'task3' => ['task2']
            ],
            'after' => [
                'task3' => ['task4']
            ]
        ];

        $merged = $this->runner->mergeRunOrder($base, $rules);

        $this->assertSame(['task1', 'task2', 'task3', 'task4'], $merged);
    }

    public function testSkipAndOnlyFilters(): void
    {
        $this->runner->addTask('task1', function () { $this->executed[] = '1'; });
        $this->runner->addTask('task2', function () { $this->executed[] = '2'; });
        $this->runner->addTask('task3', function () { $this->executed[] = '3'; });

        // Test ignoreList
        $this->runner->run(['task1', 'task2', 'task3'], ['task2'], [], false);
        $this->assertSame(['1', '3'], $this->executed);

        // Reset
        $this->executed = [];

        // Test onlyList
        $this->runner->run(['task1', 'task2', 'task3'], [], ['task2'], false);
        $this->assertSame(['2'], $this->executed);

        // Reset
        $this->executed = [];

        // Test ignoreAll
        $this->runner->run(['task1', 'task2'], [], [], true);
        $this->assertEmpty($this->executed);
    }
}
