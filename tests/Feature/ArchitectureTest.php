<?php

arch()->expect('Modules\\AI\\Contracts')->toBeInterfaces();

arch()->expect('Modules\\AI\\Enums')->toBeEnums();

arch()->expect('Modules\\AI\\Actions')->toBeClasses()->not->toUse('Mockery');
