<?php

namespace Modules\AI\Enums;

/**
 * The fixed set of operations the console offers. Each maps to one existing command or queue
 * action, and the enum is the allow-list that keeps an unknown key out of the job.
 */
enum AiOperation: string
{
    case RunDueWork = 'run-due-work';
    case Prune = 'prune';
    case ReconcileLanguage = 'reconcile-language';
    case SampleScores = 'sample-scores';
    case RetryFailedJobs = 'retry-failed-jobs';
    case ClearCaches = 'clear-caches';
    case RestartWorker = 'restart-worker';
}
