<?php

namespace CCQueue\Tests\Fakes;

class FakeGrammar
{
    public function compileSavepoint($name)
    {
        return 'SAVEPOINT ' . $name;
    }
}
