<?php

use Dynamic\ContentApi\Auth\ColymbaAuthHardening;

// colymba's api/auth/* action list can't be narrowed from YAML (#237); see
// ColymbaAuthHardening for the string-keyed map projects configure instead.
ColymbaAuthHardening::apply();
