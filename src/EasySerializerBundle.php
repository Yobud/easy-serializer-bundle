<?php

namespace Yobud\Bundle\EasySerializerBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class EasySerializerBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
