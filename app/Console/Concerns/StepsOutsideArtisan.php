<?php

namespace App\Console\Concerns;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A command whose phases Run now calls by hand (SteppableAutomation) has
 * no input or output bound, because artisan never ran it: $this->option()
 * and $this->info() would fail. This binds the job's own arguments as the
 * input and a buffer as the output, so a phase reads the same options the
 * scheduler hands it and its lines go nowhere the operator has to read.
 */
trait StepsOutsideArtisan
{
    /** @param array<string, mixed> $arguments the job's commandArguments(): "--flag" => value */
    protected function prepareForStep(array $arguments = []): void
    {
        $input = new ArrayInput($arguments, $this->getDefinition());
        $input->setInteractive(false);
        $this->input = $input;
        $this->output = new OutputStyle($input, new BufferedOutput());
    }
}
