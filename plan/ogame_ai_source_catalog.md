# OGameX AI — source links from this conversation

**Unique URLs:** 127  
**Provenance:** Every URL below was previously written in this conversation. No new URL was inferred, discovered, or guessed for this export.  
**Verification:** This is a transcript-derived index, **not a live link audit**. Earlier claims about dataset sizes, licenses, downloadability, or real-human content may be mistaken or outdated. Entries labeled as archive queries or potential captures are **not proof that a dump exists**.  
**Use:** Start with human conversation corpora for social interpretation and human gameplay/event archives for strategic decisions. Do not mix human and synthetic data without provenance labels. Review terms/consent before training on chat logs.

## Quick shortlist for your current objective

- [Cornell Deception in Diplomacy](https://convokit.cornell.edu/documentation/diplomacy.html): actual human strategic diplomacy.
- [Betrayal relationships](https://vene.ro/betrayal/): historical alliance trust and betrayal.
- [CL-Aff Diplomacy](https://github.com/kokiljaidka/claff-diplomacy): annotated relationship language.
- [STAC Catan](https://www.irit.fr/STAC/corpus.html): human resource negotiation in a game.
- [LLMafia](https://huggingface.co/datasets/niveck/LLMafia): mixed human/AI group Mafia; filter to human turns.
- [Werewolf Among Us](https://huggingface.co/datasets/bolinlai/Werewolf-Among-Us): real human social-deduction transcripts.
- [OpenFront human games](https://huggingface.co/datasets/djmango/openfront-human-games): human diplomacy **actions**, not verified natural-language chats.
- [OStats historical exports](https://ostats.hd-wg.de/): original source, but original dump file availability remains unconfirmed.

---

## Hugging Face: diplomacy, strategic conversation, multiplayer social behavior (18)

| Resource | Type / use | Caveat |
|---|---|---|
| [OgameGuid (user-provided URL)](https://huggingface.co/datasets/Shinjo/OgameGuid) | OGame instruction dataset | Spelling/accessibility unverified; retain exactly as supplied |
| [OgameGuide (assistant-mentioned variant)](https://huggingface.co/datasets/Shinjo/OgameGuide) | OGame instruction dataset | Different spelling; verify which repository exists |
| [Diplomacy text generation (Lots-of-LoRAs)](https://huggingface.co/datasets/Lots-of-LoRAs/task1590_diplomacy_text_generation) | Human dialogue excerpts | Derived/small task; underlying source overlaps possible |
| [FLAN Diplomacy task (supergoose)](https://huggingface.co/datasets/supergoose/flan_combined_task1590_diplomacy_text_generation) | Human dialogue excerpts | May repeat task1590, deduplicate |
| [Werewolf Among Us](https://huggingface.co/datasets/bolinlai/Werewolf-Among-Us) | Human deception dialogue | Transcripts/role annotations; source rights need review |
| [Werewolf dialogue segments](https://huggingface.co/datasets/iohadrubin/werewolf_dialogue_data_10sec_v2) | Human deception dialogue | Likely overlaps Werewolf Among Us |
| [LLMafia](https://huggingface.co/datasets/niveck/LLMafia) | Mixed human/AI Mafia group dialogue | Separate human turns from AI turns |
| [AIWolf + MafiaScum collection](https://huggingface.co/datasets/Saadi01/llm-werewolf-data) | Mixed logs and human forum threads | Filter mafiascum human data; rest is not necessarily human |
| [CaSiNo (HF mirror)](https://huggingface.co/datasets/kchawla123/casino) | Human negotiation conversations | Resource allocation and concession dialogues |
| [Deal or No Deal (HF)](https://huggingface.co/datasets/mikelewis0/deal_or_no_dialog) | Human negotiation conversations | Bargaining with private utilities |
| [Craigslist Bargaining](https://huggingface.co/datasets/stanfordnlp/craigslist_bargains) | Human negotiation conversations | Non-game bargaining; verify licensing |
| [Mutual Friends](https://huggingface.co/datasets/stanfordnlp/mutual_friends) | Human cooperative dialogue | Information sharing and common ground |
| [FIREBALL](https://huggingface.co/datasets/lara-martin/FIREBALL) | Human gameplay dialogue | Discord tabletop play; action-state context |
| [Portal 2 cooperative dialogue](https://huggingface.co/datasets/Berkeley-NLP/portal2) | Human cooperative gameplay dialogue | Spatial cooperation more than diplomacy |
| [Lucie training (contains STAC)](https://huggingface.co/datasets/OpenLLM-France/Lucie-Training-Dataset) | Mixed training corpus | STAC nested inside; prefer original annotated STAC |
| [Discord Dialogues](https://huggingface.co/datasets/mookiezi/Discord-Dialogues) | Large general chat | Source consent and game specificity unclear |
| [C2C AI-versus-AI](https://huggingface.co/datasets/negotiation-games/c2c-ai-vs-ai) | AI negotiation/game logs | NOT human dialogue; useful for simulations only |
| [AmongUs-X](https://huggingface.co/datasets/Among-us-X/AmongUs-X) | Synthetic/AI social deduction | NOT verified real-human dialogue |

## Human diplomacy & conversation corpora (original research and downloads) (18)

| Resource | Type / use | Caveat |
|---|---|---|
| [Cornell Deception in Diplomacy](https://convokit.cornell.edu/documentation/diplomacy.html) | Human strategic negotiation | High priority; 17k+ messages reported |
| [Linguistic Harbingers of Betrayal](https://vene.ro/betrayal/) | Human alliance relationship histories | Public subset rather than entire study corpus |
| [CL-Aff Diplomacy](https://github.com/kokiljaidka/claff-diplomacy) | Human diplomacy annotations | Rapport and affiliation labels |
| [STAC Catan corpus](https://www.irit.fr/STAC/corpus.html) | Human trade/game dialogue | Multiplayer resource negotiations |
| [STAC situated corpus page](https://www.textlink.ii.metu.edu.tr/stac-situated-corpus) | Human dialogue + game state | Companion reference; accessibility unverified |
| [STACSettlers research implementation](https://github.com/sorinMD/StacSettlers) | Human Catan research code/dump reference | Mentions human.sql; verify actual availability |
| [Avalon-NLU](https://github.com/sstepput/Avalon-NLU) | Human social deduction games | Small dataset of actual played games |
| [CaSiNo original corpus](https://convokit.cornell.edu/documentation/casino-corpus.html) | Human resource negotiation | Same underlying corpus as HF CaSiNo (not additional examples) |
| [Deal or No Deal original code/data](https://github.com/facebookresearch/end-to-end-negotiator) | Human bargaining corpus/code | Same underlying corpus as HF deal_or_no_dialog |
| [Minecraft Collaborative Dialogue](https://juliahmr.cs.illinois.edu/Minecraft/ACL2019.html) | Human game coordination | Instructions/actions, not diplomacy proper |
| [FIREBALL original corpus](https://github.com/zhudotexe/FIREBALL) | Human D&D logs | Original source, overlaps HF FIREBALL |
| [DeliData ConvoKit](https://www.convokit.cornell.edu/documentation/deli.html) | Human multiparty deliberation | Group decision conversations |
| [OvercookedChat](https://github.com/UEC-InabaLab/OvercookedChat) | Human cooperative gameplay dialogue | Japanese team communication |
| [New Shores: A Game for Democracy](https://zenodo.org/records/18174533) | Human group decision gameplay | Small research dataset |
| [PersuasionForGood](https://convokit.cornell.edu/documentation/persuasionforgood.html) | Human persuasion dialogues | Adjacent non-game corpus |
| [GAP Group Discussion](https://www.convokit.cornell.edu/documentation/gap.html) | Human group decision dialogues | Adjacent non-game corpus |
| [Conversations Gone Awry](https://convokit.cornell.edu/documentation/awry.html) | Human conversational escalation | Adjacent non-game corpus |
| [Conversations Gone Awry (CMV)](https://convokit.cornell.edu/documentation/awry_cmv.html) | Human disagreement/conflict | Adjacent non-game corpus |

## Strategy-game data, world histories, and recorded actions (14)

| Resource | Type / use | Caveat |
|---|---|---|
| [OpenFront human matches](https://huggingface.co/datasets/djmango/openfront-human-games) | Human alliance/attack action history | No verified free-text chat; large archive |
| [StrategyBench](https://huggingface.co/datasets/taso-labs/strategybench) | AI decision traces | AI players, not human decisions |
| [Catan ~43k human games](https://github.com/OrgadYron/catan_43k_games_dataset) | Human gameplay/trading events | Large logs; usage rights need checking |
| [Tribal Wars world data/API project](https://github.com/Freyhold/Tribal-Wars-API) | Player/tribe/conquest exports | Documentation/client; not a prepackaged model dataset |
| [Grepolis world data PHP API](https://github.com/RIAEvangelist/PHP-Grepolis-JSON-API) | Player/alliance/conquest exports | Documentation/client; not a prepackaged dataset |
| [Travian map.sql documentation](https://support.travian.com/en/articles/156-what-is-mapsql) | Player/village/alliance snapshots | Daily world state, not conversation transcripts |
| [zKillboard API docs](https://zkillboard.com/api/docs/) | EVE battle logs | Combat and fleet/corp interactions, not messages |
| [zKillboard history index (example day)](https://r2z2.zkillboard.com/history/20160816.json) | EVE battle archive endpoint | Documented sample day; availability not rechecked |
| [zKillboard raw history (example day)](https://r2z2.zkillboard.com/history/raw/20160816.json) | EVE battle archive endpoint | Documented sample day; availability not rechecked |
| [zKillboard history totals](https://r2z2.zkillboard.com/history/totals.json) | EVE archive index | Count index |
| [NationStates nation archives](https://www.nationstates.net/page=archive/folder=nations) | Daily nation snapshots | Social group state, not chats |
| [NationStates region archives](https://www.nationstates.net/page=archive/folder=regions) | Daily region snapshots | Social group state, not chats |
| [SC2EGSet](https://huggingface.co/datasets/Kaszanas/SC2EGSet) | StarCraft II state/replays | Tactical decisions, not diplomacy |
| [SC2-Dynamics-50K](https://huggingface.co/datasets/yxzhang2024/SC2-Dynamics-50K) | StarCraft II transition data | World dynamics, not diplomacy |

## OGame datasets, statistical archives, and original source material (33)

| Resource | Type / use | Caveat |
|---|---|---|
| [Original OStats archive site](https://ostats.hd-wg.de/) | Historical OGame database exports | Old site; offline/unavailable previously |
| [Known OStats compressed alliance dump](https://ostats.hd-wg.de/alliance.2019-09-23.sql.xz) | Historical OGame SQL dump name | User provided; file availability not confirmed |
| [Wayback OStats homepage snapshot](https://web.archive.org/web/20191207183828/https://ostats.hd-wg.de/) | Archived directory listing | Page snapshot != file payload |
| [Wayback guessed dump capture URL](https://web.archive.org/web/20191207183828/https://ostats.hd-wg.de/alliance.2019-09-23.sql.xz) | Potential archive payload | Not confirmed a captured dump |
| [Wayback exact dump CDX index](https://web.archive.org/cdx/search/cdx?url=ostats.hd-wg.de%2Falliance.2019-09-23.sql.xz&output=json) | Archive index query | Index may contain zero captures |
| [Wayback all OStats captures](https://web.archive.org/web/*/ostats.hd-wg.de/*) | Archive query | Wildcard browse, not direct dataset |
| [Wayback OStats root captures](https://web.archive.org/web/*/https://ostats.hd-wg.de/) | Archive query | Wildcard browse, not direct dataset |
| [OStats collector source](https://github.com/chrko/ostats) | Collector/schema code | Source code is not a populated DB export |
| [OStats official forum announcement](https://board.en.ogame.gameforge.com/index.php?thread/810162-ostats-poc-collecting-every-xml-api-endpoint/) | Historical export announcement | Source for existence/history, not file download |
| [OStats Origin forum announcement](https://board.origin.ogame.gameforge.com/index.php/Thread/11273-OStats-PoC-collecting-every-xml-api-endpoint/) | Historical export announcement | Old forum path may have changed |
| [OStats new statistics site](https://ostats.eu) | OGame alliance history/statistics | Different project than original 2019 OStats |
| [OStats 2026 announcement thread](https://forum.origin.ogame.gameforge.com/forum/thread/329-ostats-analytics-and-statistics/) | Alliance stats project announcement | Not original 2019 exports |
| [MMORPG-Stat](https://www.mmorpg-stat.eu/) | Historical OGame rankings/alliances | Bulk dump availability unverified |
| [MMORPG-Stat original thread](https://board.origin.ogame.gameforge.com/index.php/Thread/9753-Site-mmorpg-stat-eu/) | Historical project announcement | Potential maintainer lead |
| [OGMem combat report archive](https://ogmem.com) | Human-published combat reports | Selectively submitted; not all battles |
| [OGMem historical announcement](https://forum.origin.ogame.gameforge.com/forum/thread/70-ogmem-your-ogame-cr-archivist/) | Combat archive project | Potential bulk export maintainer lead |
| [OGame Origin API docs](https://forum.origin.ogame.gameforge.com/forum/thread/44-ogame-api/) | OGame XML/combat API reference | Documentation not a historical dump |
| [OGame official forum](https://board.en.ogame.gameforge.com/) | Human war and alliance messages | Public threads; manual extraction may be needed |
| [TA vs LoC war thread](https://board.us.ogame.gameforge.com/index.php?postID=92006&thread%2F8719-the-associates-ta-vs-lords-of-chaos-loc%2F=) | Human diplomatic war thread | Historic game forum |
| [Universe 10 WoZ/JRC/ARG history](https://board.en.ogame.gameforge.com/index.php?postID=3151763&thread%2F335454-universe-10-19-12-07-general-overview%2F=) | Human war history thread | Historic forum narrative |
| [Universe 30 war/diplomacy history](https://board.de.ogame.gameforge.com/index.php?pageNo=3&thread%2F8958-history-statistik-uni-30%2F=) | Human war/diplomacy history | Historic German thread |
| [Universe 7 alliance history](https://board.de.ogame.gameforge.com/index.php?pageNo=9&thread%2F8221-history-of-uni7%2F=) | Human alliance history | Historic German thread |
| [OGame Expedition Tracker research](https://forum.origin.ogame.gameforge.com/forum/thread/565-expedition-tracker-building-a-community-dataset-of-expedition-outcomes/) | Community outcome dataset lead | Bulk dump availability unverified |
| [Ogniter historic project](https://github.com/dlaynes/Ogniter) | Historic OGame stats project | May lead to old historical databases |
| [GalaxyStats project](https://galaxystats.net) | Current/historical game statistics | Bulk access unverified |
| [PTRE intelligence project](https://ptre.chez.gg) | Alliance espionage/intelligence data | Private/member reports require permission |
| [SocialMUD OGame server package](https://www.socialmud.com/categories?catid=47&id=46%3Aogame-server&view=download) | Old UGamela populated test SQL lead | Earlier report suggested test records, not large live server data |
| [RaGEZONE ogame.sql reference](https://forum.ragezone.com/threads/help-ogame-sql.399763/) | Old SQL file lead | Data occupancy unknown |
| [RaGEZONE OGame releases](https://forum.ragezone.com/community/ogame-releases.421/) | Server package archive | Most packages may contain install SQL only |
| [RaGEZONE OGame community](https://forum.ragezone.com/community/ogame.418/) | Private server leads | Community pages not direct dataset |
| [Inforge private server listings](https://www.inforge.net/forum/forums/ogame-lista-server-privati.1146/) | Italian server owner leads | Forum, not direct dataset |
| [Internet Archive OGame query](https://archive.org/search?query=ogame%20OR%202moons%20OR%20xnova) | Archive search | Query, not verified download |
| [Wayback 2Moons CDX index](https://web.archive.org/cdx/search/cdx?url=2moons.de/*&output=json&filter=statuscode:200&collapse=urlkey) | Archive search | May require CDX rate-limit/access |

## OGame server source, battle engines, research and ML agents (16)

| Resource | Type / use | Caveat |
|---|---|---|
| [2Moons server source](https://github.com/jkroepke/2Moons) | PHP OGame clone engine | Schemas and logic; not populated production data |
| [2Moons forks](https://github.com/jkroepke/2Moons/forks) | Source lineage archive | Research old dumps in forks |
| [2Moons commit history](https://github.com/jkroepke/2Moons/commits/master/) | Git history | Branch/path historical link, not dataset |
| [SteemNova](https://github.com/steemnova/steemnova) | 2Moons-based multiplayer implementation | Ask maintainers about historic data |
| [New-Star](https://github.com/Yaro2709/New-Star) | OGame clone source | Schema/code only verified |
| [OClassic](https://github.com/DarthPinkPapa/oClassic) | Retro OGame source | Schema/code only verified |
| [Arathi 2Moons](https://github.com/Arathi/2moons) | Older 2Moons mirror | Schema/code only verified |
| [OG API historical collector](https://github.com/balrok/og_api) | OGame stats pipeline | Potential SQLite examples; populated history unverified |
| [OPBE battle engine](https://github.com/jstar88/opbe) | Probabilistic OGame battle engine | Synthetic training generation; not human decisions |
| [OGame RL thesis (2012)](https://cs.ru.nl/bachelors-theses/2012/Ivan_Koster___0814903___Reinforcement_Learning_and_the_Parallel_Actions_Common_Goal_problem.pdf) | Research paper | OGame reinforcement learning attempt |
| [OGame RL thesis source archive](https://docs.google.com/open?id=0B8xFlIzMhHcpTkhRbFVsWTZ4dFE) | Legacy research code download | Availability unverified |
| [ogame-agi](https://github.com/Shinigallo/ogame-agi) | LLM OGame gameplay bot | Agent implementation; not OGame-trained weights |
| [ogame-agent](https://github.com/TheOnlyBeardedBeast/ogame-agent) | LLM OGame gameplay bot | Agent implementation; not OGame-trained weights |
| [ogamebot](https://github.com/eracle/ogamebot) | Automation bot prototype | Not verified trained ML model |
| [7omate ogame project](https://github.com/7omate/ogame) | OGame API collection | Data extraction not trained weights |
| [Galaxytool revived](https://github.com/supermopf/galaxytool) | OGame intel tool | Data storage/schema and maintainer lead |

## Other strategic AI research, open weights, environments (9)

| Resource | Type / use | Caveat |
|---|---|---|
| [Meta Cicero code + checkpoints](https://github.com/facebookresearch/diplomacy_cicero) | Diplomacy trained dialogue/strategic agent | Weights restricted to noncommercial use as earlier described |
| [Cicero dialogue model card](https://github.com/facebookresearch/diplomacy_cicero/blob/main/model_cards/dialogue.md) | Model card | Checkpoint information, not dataset itself |
| [Cicero redacted games](https://github.com/facebookresearch/diplomacy_cicero/tree/main/data/cicero_redacted_games) | Released game logs | Redacted subset |
| [DipNet research](https://github.com/diplomacy/research) | Diplomacy trained strategy policies | Game histories and model weights, subject to usage conditions |
| [Fine-grained Diplomacy negotiation research](https://arxiv.org/abs/2512.18292) | Research paper | Lead for more human diplomacy data |
| [CivRealm](https://github.com/bigai-ai/civrealm) | Freeciv RL environment | Simulation framework, not human chats |
| [Catan MCTS/NN AI](https://github.com/8910work-cell/catan-ai) | Trained game-value network | Weights/checkpoints claimed; verify repo |
| [Overmind-RL Screeps](https://github.com/bencbartlett/Overmind-RL) | Screeps RL environment | Agent research code |
| [LLMafia research code](https://github.com/cocochief4/llm-mafia) | Synthetic/mixed Mafia agent research | Do not treat as human-only dataset |

## Server-owner/maintainer contact leads (not directly downloadable datasets) (12)

| Resource | Type / use | Caveat |
|---|---|---|
| [Christian Kohlstedt OStats developer](https://github.com/chrko) | Maintainer contact lead | Email was user-provided separately; not a dataset |
| [ImperatorMaximus forum profile](https://board.en.ogame.gameforge.com/wcf/index.php?user/170242-imperatormaximus/) | Historical OStats maintainer | Contact lead |
| [Xterium support](https://xterium.com/ar/support) | Private game server contact | Ask for permitted, sanitized archives |
| [Xterium developer post](https://xterium.com/forum/topic/6852-perestroika-pvp-i-sviazannykh-mekhanik-woa/) | Private server owner lead | Maintainer activity |
| [SteemNova historical Discord invite](https://discord.gg/g6UHwXE) | Private server maintainer contact | Invite may be expired |
| [DeltaWar original forum thread](https://www.inforge.net/forum/threads/deltawar-6-0-%E2%98%85-miglior-server-privato-ogame-italiano-23-09-2015-%E2%98%85.432258/) | Private server owner lead | Historic account may be inactive |
| [OGameXL private server thread](https://forum.ragezone.com/threads/ogame-private-server-step-into-space-%F0%9F%9A%80.1240255/) | Private server owner lead | Contact thread |
| [OGameXL server](https://ogamexl.com) | Private server website | Not a dataset |
| [SpaceWars Retro thread](https://www.inforge.net/forum/threads/spacewars-retroserver-ogame-100-versione-0-84.621305/) | Private server owner lead | Historic thread |
| [StarNova thread](https://www.inforge.net/forum/threads/starnova-ultimatexnova-server-based.619354/) | Private server owner lead | Historic thread |
| [PTRE Discord](https://discord.gg/PQkSakJSPz) | Intel dataset maintainer contact | Invite validity unverified |
| [GalaxyStats Discord](https://discord.gg/W6Er7Mugwf) | Game stats maintainer contact | Invite validity unverified |

## ML engineering resources mentioned (reference, not datasets) (7)

| Resource | Type / use | Caveat |
|---|---|---|
| [MiniLM sentence encoder](https://huggingface.co/sentence-transformers/all-MiniLM-L6-v2) | Pretrained text embeddings model | Not trained for OGame decision-making |
| [PyTorch basic tutorials](https://docs.pytorch.org/tutorials/beginner/basics/intro.html) | Training documentation | General reference |
| [PyTorch build a network](https://docs.pytorch.org/tutorials/beginner/basics/buildmodel_tutorial.html) | Training documentation | General reference |
| [Gymnasium custom environment tutorial](https://gymnasium.farama.org/tutorials/gymnasium_basics/environment_creation/) | RL simulation conventions | General reference |
| [Gymnasium](https://gymnasium.farama.org/) | RL framework | General reference |
| [PettingZoo](https://pettingzoo.farama.org/) | Multi-agent RL interface | General reference |
| [ONNX Runtime docs](https://onnxruntime.ai/docs/) | CPU model inference | General reference |

