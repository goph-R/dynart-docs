<?php

namespace Dynart\Docs;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Docs\Build\DocsBuilder;

/**
 * `dpress docs:build` - the build, for a deploy or a cron job
 */
class DocsCommands {

    public function __construct(
        protected DocsBuilder $builder,
        protected CliOutputInterface $output,
    ) {}

    /**
     * `dpress docs:build [-source <folder>]`
     *
     * `-source` builds from another folder than the setting names, without changing it - for
     * trying a checkout out before pointing the site at it.
     *
     * Answers 0 when there are pages, whatever was reported about them: a broken link is worth
     * reading, not worth failing a deploy over. 1 when nothing could be built at all.
     */
    public function build(array $params = []): int {
        $folder = isset($params['source']) && trim((string)$params['source']) !== ''
            ? rtrim((string)$params['source'], '/\\') : null;
        $this->output->writeLine('Building from '.($folder ?? $this->builder->sourceFolder()).' ...');
        $report = $this->builder->build($folder);
        if ($report->update !== '') {
            $this->output->writeLine($report->update);
        }
        foreach ($report->problems as $problem) {
            $this->output->setColor(CliOutput::YELLOW);
            $this->output->write('  '.($problem['file'] !== '' ? $problem['file'].': ' : ''));
            $this->output->setColor(null);
            $this->output->writeLine($problem['message']);
        }
        if ($report->pages === 0) {
            $this->output->setColor(CliOutput::RED);
            $this->output->writeLine('Nothing was built.');
            $this->output->setColor(null);
            return 1;
        }
        $this->output->setColor(CliOutput::GREEN);
        $this->output->writeLine("Built {$report->pages} page(s) under /".$this->builder->base()
            .(count($report->problems) > 0 ? ', with '.count($report->problems).' thing(s) to look at.' : '.'));
        $this->output->setColor(null);
        return 0;
    }
}
