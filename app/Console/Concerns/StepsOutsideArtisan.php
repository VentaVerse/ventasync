<?php

namespace App\Console\Concerns;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

trait StepsOutsideArtisan
{
    protected function prepareForStep(array $arguments = []): void
    {
        $input = new ArrayInput($arguments, $this->getDefinition());
        $input->setInteractive(false);
        $this->input = $input;
        $this->output = new OutputStyle($input, new BufferedOutput());
    }
}
