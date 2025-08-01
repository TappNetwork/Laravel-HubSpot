<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('php preset')->preset()->php();

arch('security preset')->preset()->security();

arch('laravel preset')->preset()->laravel();
