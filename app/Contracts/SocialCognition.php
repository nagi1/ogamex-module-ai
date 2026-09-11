<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Domain\Conversation\SocialExchangeEvaluation;

interface SocialCognition
{
    public function evaluateSocialExchange(SocialExchangeContext $exchange): SocialExchangeEvaluation;
}
