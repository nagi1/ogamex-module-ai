using System;
using System.Collections.Generic;

namespace CommeillFaut.DTOs
{
    /// <summary>
    /// The current stance of one authored social exchange towards a counterparty.
    /// Added by OGameX: exchange state was only reachable indirectly through authored
    /// decision rules, so an external caller could not evaluate an exchange at all.
    /// </summary>
    [Serializable]
    public class SocialExchangeEvaluationDTO
    {
        public string Name { get; set; }

        public string Description { get; set; }

        public string Target { get; set; }

        /// <summary>The step that is currently active for this target.</summary>
        public string Step { get; set; }

        public string[] Steps { get; set; }

        /// <summary>
        /// Volition per usable mode at the current step. A mode is absent when it is not
        /// usable, which the engine reports as negative infinity rather than zero.
        /// </summary>
        public Dictionary<string, float> Volitions { get; set; }
    }
}
