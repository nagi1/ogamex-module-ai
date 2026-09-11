<?php

namespace Modules\AI\Enums;

enum AiLanguageProposalRejectionReason: string
{
    case SourceNotAuthorized = 'source_not_authorized';
    case SourceNotObserved = 'source_not_observed';
    case TermsNotExplicit = 'terms_not_explicit';
    case Unsupported = 'unsupported';
}
