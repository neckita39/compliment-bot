<?php

namespace App\Service;

interface ComplimentGeneratorInterface
{
    public function generateCompliment(?string $name = null, string $role = 'wife'): string;
}
