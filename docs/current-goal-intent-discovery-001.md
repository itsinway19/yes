# Current Goal / Current Intent — bounded discovery 001

**التاريخ:** 2026-10-04، Europe/Istanbul. **النطاق:** استكشاف المستودع والدلالات فقط؛ هذه الوثيقة ليست specification أو implementation plan أو تفويضًا بالتنفيذ.

## خلاصة للمالك

لا يوجد في المرجع المفحوص تنفيذ canonical للنية التجارية الحالية أو لمعنى نتيجة الأعمال المطلوبة. توجد هوية نشاط فعلية عبر Workspace، وسلطة Business Context، وإصدار مشترك، وكتابة متسلسلة، وسجل للأسواق المعلنة يمكن الاستفادة من دلالاتها لاحقًا. بيانات المزوّدين الحالية أدلة إعلانية مصدرية، وليست تصريحًا بنية المعلن أو إثباتًا لنتيجة أعمال مؤهلة.

الحد الأدنى المرشح: تغيير تجاري يصرّح المعلن بأنه يريد تحقيقه الآن، يستهلك معنى واضحًا للنتيجة التي تُحسب تجاهه؛ نية حالية واحدة، وتاريخ تغييرات محفوظ. العدد والمهلة ليسا إلزاميين لكل نية. سقف الإنفاق الإعلاني المشترك حقيقة مستقلة يستهلكها القرار، ولا يصبح ملكًا للنية لمجرد أن القرار يحتاجه. يمكن الانتقال إلى **تصميم Goal محدود بعد مراجعة المالك**؛ مثال إعادة توزيع الإنفاق غير جاهز للتنفيذ بالأدلة الحالية.

## 0. المرجع وطريقة الاستكشاف

| فحص الدخول | النتيجة الفعلية |
| --- | --- |
| HEAD | `2cf51ea337b26e07bfcaff59ed9d3e81c632a50e` — مطابق |
| الفرع | `codex/current-goal-intent-discovery-001` — مطابق |
| Worktree | `C:\dev\builderad-worktrees\current-goal-intent-discovery-001` — مطابق |
| حالة الملفات قبل الاستكشاف | `git status --short` فارغ |

فُحصت الملفات المتعقبة والبنية الحالية، وأُجريت عمليات بحث على مستوى المستودع، بما فيه الملفات المخفية غير `.git`، ثم قراءات محددة للحدود ذات الصلة. شمل البحث الأسماء والبدائل: `goal/goals`, `intent`, `objective`, `desired outcome`, `target`, `target value`, `business/campaign objective`, `optimization/conversion goal`, `success criteria`, `KPI`, `result/outcome`, `qualified lead/buyer/prospect`, `conversion`, `purchase`, `sale/order/booking/contract`، ومصطلحات عربية مثل هدف، نتائج، مؤهل، حجز، مبيعات. استُبعدت بيانات مرجع الدول وملفات dependency lock من البحث الدلالي، ولم تُقرأ أسرار أو ملفات بيئة خاصة.

جرى التمييز بين نتائج البحث الحقيقية والتشابه اللفظي: `ORDER BY` ليس طلب شراء؛ DOM target ليس عددًا مطلوبًا؛ security target هو نطاق سلطة؛ command result هو إيصال إجراء؛ commercial qualification أهلية مستفيد؛ `purpose=advertising_analysis` غرض استخدام بيانات. لا يمثل أي منها نية تجارية أو نتيجة أعمال.

المصدر الحاكم للحدود المستقبلية: `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:191`، `:202`، `:335`، `:1084`، `:1263`، `:2516`. وثائق التنفيذ السابقة أدلة تاريخية مساعدة، وليست إثباتًا جديدًا لتشغيل هذا الـHEAD. لم تُشغّل Laravel أو اختبارات قاعدة البيانات أو migrations أو متصفحًا أو مزوّدًا خارجيًا، ولم يحدث اتصال بقاعدة Builderad الأساسية. هذا تقرير source inspection؛ لا يقدّم شهادة تشغيل أو نجاح اختبارات جديدة.

## A. Current system state

`COMPLETE` أدناه يعني اكتمال السطح المحدد في المصدر، وليس اكتمال Core أو إثبات نشره. `PRESENT_BUT_UNVERIFIED` يفصل وجود الكود/الاختبار عن عدم تشغيله في هذه المهمة.

| AREA | STATUS | EVIDENCE: paths / symbols | IMPLICATION |
| --- | --- | --- | --- |
| هوية النشاط الفعلية | COMPLETE | `app/Models/Workspace.php`؛ `app/Foundation/Tenancy/WorkspaceContext.php:37`، `execute`؛ `database/migrations/0001_01_01_000010_create_workspace_foundation.php` | Workspace هو الهوية الحالية؛ UUID المسار يحدد النشاط، ولا حاجة إلى Business profile أو current-business resolver موازٍ. |
| Business Context: الاسم والنوع والملكية | COMPLETE | `database/sql/business_context.sql:1`، `:10`، `:11`؛ `app/Business/BusinessContext.php:39`، `write`؛ `:47`، `projection` | الاسم `workspaces.name` والنوع `business_type_id` حقيقتان معلنتان؛ Business Context مالك دلالي مناسب للنية المعلنة. |
| Declared Target Markets | COMPLETE | `database/sql/declared_target_markets.sql:8`، `:40`، `builderad_business_target_markets_write`؛ `app/Business/BusinessContext.php:20`؛ `routes/web.php:62`؛ `database/migrations/0001_01_01_000330_create_declared_target_markets.php` | مجموعة دول محايدة مملوكة للنشاط؛ المجموعة الفارغة غير معلنة، وليست بلد الحساب أو السوق التجاري أو فرصة مستنتجة. |
| سلطة كتابة Business Context | COMPLETE | `app/Business/BusinessContext.php:33`، `canManage`؛ `database/sql/business_context_lock_order.sql:42`، `builderad_business_context_write`؛ `database/sql/source_registry.sql:95`، `builderad_source_actor`؛ `tests/Feature/BusinessFoundationTest.php:130`، `:150` | جلسة بشرية موقعة، عضوية نشطة، مالك مسؤول صالح، وصلاحية دقيقة `business.context.manage`؛ دور owner وحده غير كافٍ، وغير المالك المخوّل يستطيع التعديل. |
| الإصدار المشترك والتعارض | COMPLETE | `database/sql/business_context.sql:11`؛ `database/sql/business_context_lock_order.sql:61`، `:65`، `:68`؛ `database/sql/declared_target_markets.sql:68`، `:69`، `:74` | تغيير حقيقي يرفع الإصدار مرة؛ تغيير مختلف بإصدار قديم يفشل بـ`40001`؛ الطلب المطابق المصرّح به no-op حتى مع إصدار قديم. |
| تسلسل الكتابة والتحقق بعد الانتظار | COMPLETE | `app/Foundation/Tenancy/WorkspaceContext.php:32`، `runBusinessMutation`؛ `database/sql/business_context_lock_order.sql:3`، `:15`، `:27`؛ migration `000330` يثبت الإصلاح forward | بوابة Business Context المحلية قبل selector؛ لا يجوز إضافة writer للنية يتجاوز التحضير أو يصنع serialization مستقلة. |
| سجل الاسم والنوع | PARTIAL | `database/sql/business_context.sql:45`، `business_context_events`؛ `:64`، `:69`، `:74`، `:77`؛ `database/sql/business_context_lock_order.sql:70`؛ `tests/Feature/BusinessFoundationTest.php:173` | يحتفظ قبل/بعد والممثل والإصدار والزمن، وruntime يقرأ فقط. لم يُعثر على trigger يمنع UPDATE/DELETE لهذا الجدول كما في DTM؛ لا يُنسب إليه ضمان مماثل ضد الكاتب ذي الامتيازات. |
| سجل DTM غير القابل للتعديل | COMPLETE | `database/sql/declared_target_markets.sql:13`، `business_target_market_events`؛ `:37`، `target_market_event_immutable`؛ `tests/Feature/DeclaredTargetMarketsDatabaseTest.php:160` | أقرب نمط قائم لتاريخ تصريحات النية: قيمة حالية + أحداث انتقال immutable، دون lifecycle engine. |
| إثبات التشغيل والتزامن في هذا الـworktree | PRESENT_BUT_UNVERIFIED | `tests/Feature/BusinessContextLockOrderTest.php`؛ `tests/Feature/DeclaredTargetMarketsConcurrencyTest.php:14` إلى `:114`؛ `tests/TargetMarketsCommandWorker.php`؛ سجل سابق `docs/decision-context-foundation-001-implementation-proof.md` | اختبارات PostgreSQL فعلية موجودة في المصدر، لكنها لم تُشغّل هنا؛ لا ادعاء PASS جديد أو صلاحية تشغيل Goal مستقبلية. |
| Current Goal / Current Intent التجارية | MISSING | البحث العام؛ `app/Business/BusinessContext.php:47`؛ `app/Http/Controllers/BusinessContextController.php`؛ `routes/web.php:56`؛ `docs/BUSINESS_FOUNDATION_IMPLEMENTATION_REPORT.md`، بند 28 المؤجل | لا تخزين/كاتب/route/UI/اختبار لنية أعمال حالية؛ غيابها لا يُستنتج من كلمة Goal وحدها. |
| استعمالات intent التشغيلية | COMPLETE | `app/Providers/Ads/Google/GoogleFlowState.php`، `issueConnectIntent`؛ FlowState للمزوّدين الآخرين؛ `app/Providers/Ads/X/XSourceBootstrap.php`؛ `app/Http/Controllers/CommercialMarketPreparationController.php`، `newIntent` و`intent_id` | مقابض correlation/هوية أمر وإجراء داخلي؛ ليست مفهوم Goal متداخلًا ولا سببًا لإيقاف الاستكشاف. |
| canonical Desired Outcome Meaning | MISSING | `app/Providers/Ads/AdObservation.php:8`؛ `app/Providers/Ads/Google/GoogleObservationAdapter.php:170`؛ الحوكمة `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:202`، `:335` | المالك المنفّذ غائب، لكن الفصل الدلالي مقرر في الحوكمة. يلزم معنى نتيجة محدد، ولا يلزم Outcome System الآن. |
| نتيجة أعمال مرصودة ومقبولة / تأهيل / بيع / حجز | MISSING | البحث الدلالي في app/database/routes؛ `app/Providers/Ads/AdObservation.php`؛ `tests/Feature/Commercial/CommercialScopeTest.php` | لا qualification engine ولا سجل نتائج أعمال مؤهلة ولا mapping معتمد بين حدث المزوّد والنتيجة التجارية. |
| رصد إعلاني وحفظ تاريخ المصدر | COMPLETE | `database/sql/provider_observation.sql:2`، `:14`، `:22`، `:37`؛ `app/Providers/Ads/ObservationJournal.php`؛ `AdObservation::identity/contentHash` | حقيقة مرصودة ذات provenance وحالات reread/restatement؛ مفيدة للأدلة لاحقًا، ولا تُرقّى إلى نتيجة أعمال بمجرد تطبيع الغلاف. |
| تحويلات مصدرية عبر المزوّدين | PARTIAL | `app/Providers/Ads/Google/GoogleObservationAdapter.php:64`، `:170`؛ `app/Providers/Ads/Google/GoogleCampaignCsv.php`؛ بقية adapters في الجدول التالي | Google يحفظ conversion counts/value بدلالات مصدرية؛ لا عقد conversion business موحد، ولا دليل مؤهل متناظر بين Google وMeta. |
| أهداف المزوّدين وإعدادات التحسين والتحويل | MISSING | قوائم الحقول الفعلية للـ5 readers في الجدول التالي؛ البحث عن objective/optimization_goal/conversion_action | لا تمثيل صريح حالي لتلك الإعدادات في الأسطح المفحوصة. عدم جلبها لا يعني أن المزوّد نفسه لا يدعمها. |
| الإنفاق المرصود | COMPLETE | `AdObservation::$spend`؛ `app/Providers/Ads/NativeAmount.php`؛ adapters الخمسة؛ `tests/Unit/ProviderObservationContractTest.php:46`، `:53` | مبالغ مصدرية بوحداتها وعملتها، وقيمة unknown مستقلة عن zero؛ ليست ميزانية أو constraint. |
| ميزانية campaign/account، caps، allocations | MISSING | البحث العام في budget/cap/allocation وأشكالها؛ قوائم الحقول الحالية؛ `XCampaignCurrency` | لا ميزانيات إعلانية محفوظة صراحة، ولا canonical advertiser spend constraint. funding instrument هوية تمويل، وليس سقفًا. |
| حدود تشغيل سحب البيانات | COMPLETE | `app/Providers/Ads/ProviderReadBudget.php:8`، constructor؛ read attempts SQL للمزوّدين | requests/retries/pages/rows/deadline حدود تشغيل محلية؛ لا تُعاد تسميتها business budget. |
| السياق التجاري | PARTIAL | `database/sql/sa01.sql:65`، `:80`؛ `app/Commercial/RegisterCommercialScope.php`، `advertiserProjection`، `agreement_state=UNKNOWN` و`amount_state=UNKNOWN` | تسجيل المستفيد والسوق/config/catalog والعملة؛ لا ميزانية المعلن ولا مبيعاته ولا نتيجة حملته. |
| reporting windows وtimestamps | COMPLETE | `app/Providers/Ads/ReportWindow.php`؛ report controllers الخمسة؛ `provider_observation.sql`؛ `sa01.sql`، `effective_at` | فترات قياس وحدود زمنية مصدرية وصلاحية/تاريخ تسجيل؛ ليست مهلة تحقيق نية المعلن. |
| advertiser time intent / campaign schedule | MISSING | البحث العام وحقول readers/controllers؛ `ReportWindow` | لا مهلة تجارية معلنة ولا schedule campaign محفوظ؛ لا يُستعار تاريخ التقرير ليصبح تاريخ هدف. |
| واجهة Business Context والتنقل | COMPLETE | `resources/js/Pages/Business/Context.vue`؛ `resources/js/Layouts/AdvertiserLayout.vue`؛ `resources/js/Components/Business/TargetMarketsEditor.vue`؛ `app/Http/Controllers/WorkspaceController.php`، `show` | المسار الحقيقي يعرض Business/Sources؛ السياق قابل للوصول منفصلًا. يوجد RTL وlocale ودعم drafts/conflicts؛ لا Goal surface. |
| Market opportunity / evidence | MISSING | الحوكمة `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md:1084`، `:1263`، `:2516`؛ البحث التنفيذي | GOVERNED، NOT IMPLEMENTED. priors المهيأة حسب نوع النشاط فرضيات مشروطة، وليست فرص سوق مكتشفة. |

### تمثيل كل مزوّد بالفعل

| Provider | STATUS | ما يطلبه/يحفظه الكود بالفعل | ما لا يجوز استنتاجه |
| --- | --- | --- | --- |
| Meta | PARTIAL | `app/Providers/Ads/Meta/MetaObservationAdapter.php:132`، `fields`: account/currency/campaign/date/spend/impressions/clicks | لا objective أو optimization goal أو actions/conversion evidence في هذا reader؛ لا «Meta Leads إذن نية المعلن Leads». |
| Google | PARTIAL | `app/Providers/Ads/Google/GoogleObservationAdapter.php:64`، campaign identity/status، cost، clicks/impressions، conversions/all_conversions/conversions_value؛ `:170` يشرح provider attribution | لا conversion action definition أو conversion goal/config أو campaign objective. التحويل قد يكون كسريًا أو مفقودًا، وليس عدد مشترين مؤهلين. |
| Snapchat | PARTIAL | `app/Providers/Ads/Snapchat/SnapchatObservationAdapter.php:78`، campaign TOTAL مع impressions/swipes/spend؛ `:125` يثبت دلالة المقاييس | swipe ليس qualified lead، ولا توجد هنا conversion/optimization semantics. |
| TikTok | PARTIAL | `app/Providers/Ads/TikTok/TikTokObservationAdapter.php:72`، campaign/day dimensions وspend/impressions/clicks؛ `:116` يبقي scale غير مثبت | لا objective/optimization/purchase semantics؛ لا تحويل نقدي موحد مصطنع من وحدة غير مثبتة. |
| X | PARTIAL | `app/Providers/Ads/X/XCampaignReport.php:103`، BILLING/ENGAGEMENT؛ `:171`، clicks/impressions وplacement وfunding instrument؛ `app/Providers/Ads/X/XCampaignCurrency.php` | campaign metadata تُستهلك لإثبات العملة/التمويل والاختيار؛ لا تُحفظ جميع حقول payload تلقائيًا ولا تصبح objective أو budget. |

`PARTIAL` هنا يصف تغطية الدلالات ذات الصلة بهذا الاستكشاف، ولا يصنّف جودة اتصال المزوّد أو جاهزيته الحية. `ProviderEvidenceValidity` و`EffectiveCapability` يحددان صحة أدلة الوصول/القدرة، ولا يملكان قبول نتيجة أعمال أو تأهيل مشترٍ.

## B. Collision map

| المفهوم | المعنى/المالك | الحد الفاصل عن Current Intent |
| --- | --- | --- |
| Business Type | تصنيف النشاط؛ Business Context | «عقارات» لا يقرر أن المطلوب الآن acquisition أو حماية الأداء. |
| Declared Target Markets | الدول التي يصرح النشاط باستهدافها؛ Business Context | «السعودية» تجيب أين، لا ماذا يريد تحقيقه؛ ليست مكان الشركة أو campaign targeting. |
| Current Intent | التغيير التجاري الحالي الذي يطلب المعلن مساعدة Builderad لتحقيقه | حقيقة declared؛ لا inferred، ولا إذن تلقائي بتنفيذ حملة أو نقل ميزانية. |
| Provider Objective | إعداد مصدر يحدد سلوك حملته | لا مصدر ملكية لنية النشاط؛ campaign objective لا يحسم business acceptance. |
| Desired Outcome Meaning | ما الذي يُحسب نتيجة أعمال ذات صلة بالنية | معنى مطلوب؛ ليس عددًا مستهدفًا أو قياسًا لشيء وقع. |
| Observed Outcome | ما حدث فعلًا مع دليل وقبول محددين | observed، لا desired؛ رصد Google conversion ليس قبولًا canonical. |
| Campaign Configuration | حالة/إعدادات حملة وأهدافها وميزانيتها وتوجيهها | تخص المصدر/الحملة، لا تصريح النشاط العام؛ الموجود الآن يغطي أجزاء مثل Google status فقط. |
| Spend / Budget | spend مرصود؛ budget/cap قيد موارد معلن مستقل | قيمة الإنفاق السابق لا تُنتج سقفًا؛ سقف النشاط المشترك لا يملك Goal نسخة ثانية منه. |
| Time / Schedule | موعد تجاري مطلوب، مقابل reporting/execution/observation time | deadline معلن قد يقيد النية؛ start/end في report لا يصرّح به. |
| Market Opportunity | فرصة مستنتجة/مكتشفة من دليل | لا تعيد كتابة تصريح المعلن؛ حوكمة فقط حاليًا. |

الاستعمالات المتشابهة لغويًا مصنّفة، ولم يُعثر على Goal/Outcome canonical قائم أو تداخل مادي يستدعي stop condition. الصفحات القديمة `resources/js/Pages/Workspaces/Show.vue` و`Dashboard.vue` لا تمثل تنفيذ Goal؛ controller الفعلي يعرض `Business/Sources`.

## C. Reuse map

| WHAT CAN BE REUSED | السبب/الحد |
| --- | --- |
| Workspace identity + route UUID + signed WorkspaceContext | هوية النشاط وعزله قائمة بالفعل، وليست هوية حملة أو شخص. |
| Business Context authority | المعلن/المفوّض يغيّر حقيقة business-level معلنة؛ نفس معنى الاسم والنوع وDTM، دون سلطة provider أو staff بديلة. |
| `business_context_version` + prepared mutation entry | النية تغير تفسير السياق المشترك؛ تضمينها يمنع قراءة/حفظ قرار على سياق تغيّر دون اكتشاف ذلك. |
| authorized no-op / stale material conflict / atomic revision+audit | دلالات مستقرة قائمة؛ يجب تعريف مساواة النية دلاليًا أثناء التصميم، لا بافتراض أن كل تغيير صياغة يساوي تغيير معنى. |
| DTM current value + immutable change events pattern | يحفظ التصريحات السابقة دون نظام lifecycle أو Goals management. إعادة استخدام النمط لا تعني وضع النية داخل جدول DTM. |
| Arabic advertiser shell / conditional disclosure / draft-conflict behavior | سطح نشاط موجود، وصلاحيات ورسائل conflict قائمة؛ لا حاجة إلى منتج إدارة أهداف مستقل. |
| provider observation provenance, truth basis, windows, restatements | تستخدم أدلة مصدرية في الشريحة المستقبلية مع حدودها؛ لا تملك Intent أو business acceptance. |

**WHAT MUST NOT BE REUSED:** OAuth/connect intent handles، commercial preparation intent/command identity، provider metrics/status/configuration، security target IDs، PromotionProduct أو source-priority كهدف، reporting window كمهلة تجارية، ProviderReadBudget كميزانية، spend المرصود كسقف، commercial denomination كعملة constraint مفترضة، provider observation generation كإصدار نية، وطبقات source/commercial audit custody كمالك تصريح أعمال جديد.

**WHAT IS MISSING:** إعلان النية الحالية وقراءتها/كتابتها/تاريخها، معنى النتيجة المقبولة business-wise، وربط أدلة مرصودة بذلك المعنى. القيود المالية العامة وأدلة تأهيل المشترين غير منفذة. لا تُضاف هذه كلها تلقائيًا إلى Goal.

## D. Minimum semantic contract candidate — meanings only

هذه مرشحات دلالية للتصميم اللاحق، وليست حقولًا أو schema أو enums أو endpoints.

| candidate / MEANING | WHY NEEDED + DECISION DELTA | OWNER | REQUIRED / OPTIONAL | WHAT IT MUST NOT MEAN |
| --- | --- | --- | --- | --- |
| تغيير تجاري مطلوب الآن | يفرق acquisition عن حماية أداء قائم أو تقليل الهدر؛ يغير ترتيب التدخلات وما يستحق الحفاظ عليه | Business Context، تصريح معلن للنشاط | UNIVERSALLY REQUIRED عند وجود نية معلنة | business type، campaign objective، KPI dashboard، خطة تنفيذ أو ضمان نجاح |
| معنى ما يُحسب تجاه التغيير | يمنع اختيار channel أعلى clicks بينما يفشل في نتيجة الأعمال المقصودة؛ يحدد أساس ملاءمة الدليل | Desired Outcome Meaning مستقل دلاليًا؛ النية تستهلكه، انظر E | REQUIRED للشريحة الاقتصادية؛ لا يشترط أن يكون countable لكل نية | event اسمُه lead/purchase، generic ontology أو observed result |
| معيار محدود لصفة جوهرية مثل «مؤهل» | يغير قبول/رفض النتيجة وترتيب مصادرها؛ بدون تفسير الصفة لا يصح مقارنة qualified buyers | نفس مالك Desired Outcome Meaning، بتصريح business-level | GOAL-DEPENDENT؛ required عندما تُستخدم صفة مؤثرة | qualification engine أو thresholds مخترعة من نوع النشاط أو conversion count |
| مقدار مطلوب إن كانت النية كمية | 100 مشترٍ قد يغير تقدير الحاجة والجدوى وقوة التوصية؛ لا يعطي pace وحده دون زمن | Current Intent، مقيد بمعنى النتيجة المعدودة | GOAL-DEPENDENT / OPTIONAL في أول contract | رقم دائم إلزامي، actual result أو provider target value |
| مهلة تجارية يصرّح بها المعلن | 30 يومًا تغير urgency والpace إن وُجد مقدار، وتؤثر في experiment choice؛ absent لا يُنتج موعدًا افتراضيًا | Current Intent إذا كانت موعد تحقيق التغيير؛ execution planning يملك جدوله الخاص | GOAL-DEPENDENT / OPTIONAL | report period، job deadline، audit timestamp أو نافذة التحقق من إجراء |
| نية حالية واحدة أو عدم إعلان | يحدد أي تغيير يوجّه القرار الآن، ويمنع استخدام تصريح superseded بوصفه الحالي | Business Context | UNIVERSALLY REQUIRED كدلالة currentness؛ لا يستلزم lifecycle field | «لا نريد أي نتيجة» أو enum حالات إدارة أهداف |
| نسبة التصريح إلى نشاط وممثل وتغيير محفوظ | تمنع إسناد intent لغير صاحبه أو إعادة تفسير قرار قديم بعد تعديل التصريح | Foundation authority + Business Context audit/history | REQUIRED كسلامة ملكية/تتبع، وليس سؤالًا إضافيًا للمعلن | goal-specific concurrency framework أو execution approval |
| سقف إنفاق إعلاني عام مثل 5,000 SAR | يغير feasibility وحجم التجربة وحدود action؛ يستلزم عملة ونطاقًا/فترة معلنة ليصبح ذا معنى | shared Spend/resource constraint، مالك غير منفذ حاليًا | BELONGS TO ANOTHER OWNER؛ اختياري لGoal، وقد يلزم للقرار المستقبلي | budget حملة، spend سابق، commercial fee أو حقل Goal إلزامي |
| قيد خسارة/تكلفة خاص بالتغيير نفسه | قد يغير stop/acceptance إذا كان تعريف المعلن للتغيير مثل «اختبار الاتجاه دون خسارة تتجاوز ...» | Current Intent فقط بقدر انحصار المعنى في ذلك التغيير؛ سقف موارد مشترك يظل منفصلًا | CONDITIONAL؛ لا يُرقّى لأول contract دون decision delta محدد | نسخة أخرى من ميزانية النشاط، channel allocation أو execution policy |

`DERIVABLE LATER`: pace من مقدار ومدة معلنين، تقدير الحاجة إلى إنفاق من أدلة كافية، urgency من الموعد. ليست تصريحات جديدة ولا أرقامًا يجوز اختراعها. `NOT NEEDED`: taxonomy شاملة، hierarchy، templates، lifecycle status enum، engine عام. `OPTIONAL`: شرح قصير يساعد توضيح التصريح؛ لا يكفي free text غير مفسر وحده لاتخاذ قرار اقتصادي.

## E. Minimum outcome dependency and ownership

**المطلوب الآن دلاليًا:** تحديد نوع نتيجة الأعمال التي تهم هذا التصريح، ومعيار قبول مختصر للصفة التي تغير القرار، ووحدة العد إن طُلب مقدار. «مشتري مؤهل» لا يكفي كعنوان: يجب أن يعرف المستهلك ما الذي يجعل شخصًا/حدثًا يُحسب كذلك. لا تحدد هذه المهمة شروط التأهيل نيابة عن المعلن، ولا تستنتجها من العقارات أو البلد أو event name.

بالنسبة إلى نية حماية الأداء أو تقليل الهدر، ينبغي توضيح نتيجة الأعمال التي يجب الحفاظ عليها/تحسين كفاءتها وما الذي يعد تدهورًا ذا صلة؛ لا نُجبرها على target quantity. يلزم لاحقًا دليل مقارنة/baseline مناسب للقرار، لا جدول KPI عام داخل Intent.

**Ownership ruling: A — reference / consume a separately owned Desired Outcome Meaning، كتوصية دلالية.** لا يوجد مالك Outcome منفذ يجب تغييره أو تكراره. الحوكمة تفصل Current Intent وCanonical Outcome Meaning وتطلب مالكًا واحدًا للحقيقة المشتركة. يمكن أن تبقى نتيجة «مشتري مؤهل» بنفس معناها بينما تتغير النية من زيادتها إلى حماية أدائها أو خفض كلفتها؛ تغيير target/deadline لا ينبغي أن يعيد تعريف ما يُحسب نتيجة. هذا اختلاف في الملكية، لا مجرد اختلاف أسماء.

الخيار B ليس مفروضًا لمجرد أن المثال يحتاج المفهومين معًا، ولا أوصي بأن تملك النية تعريف النتيجة كله. الفصل هنا لا يقرر relation/table/catalog/API أو نظامًا مستقلًا؛ يمكن عرض المفهومين في تفاعل صغير واحد، مع بقاء الملكية واضحة. مكان تمثيل المعنى ومن يصرّح بتغييره يثبتان في التصميم المحدود بعد مراجعة المالك. إعادة تفسير النتيجة تغيير مادي يجب أن يبقى أثره محفوظًا، لا أن يغير معنى القرارات الماضية.

**المؤجل:** Outcome System، qualification engine، conversion framework، deduplication/identity resolution، attribution acceptance، generic outcome ontology، value/revenue engine، KPI framework، CRM/commerce/SDK. للقرار القابل للتنفيذ لاحقًا يلزم بيان أي دليل يثبت المعيار وكيف نُسب للحملة وبأي فترة/coverage؛ تعريف النتيجة وحده لا يولد تلك الأدلة.

## F. Currentness / history — answers to ownership questions 7–10

1. **Immutable history من أول slice: YES، بصورة محدودة.** احتفظ بتصريح النية قبل/بعد والممثل والإصدار والزمن، وبالمعنى المقصود للنتيجة بحيث لا يُعاد تفسير الماضي. السبب قائم في DTM وprovider restatements: التصريحات والقياسات قد تتغير، ولا يجوز عرض قرار قديم كما لو أنه صدر تجاه intent جديدة. لا حاجة إلى event-sourcing framework أو Goals timeline UI.
2. **نية حالية واحدة: YES.** المثال يحتاج معيار توجيه واحدًا للقرار؛ لا يوجد في المصدر ما يوجب multi-goal priorities أو hierarchy. يمكن أن يحتوي التصريح الواحد حدودًا مترابطة؛ لا نوسع ذلك إلى عدة نيات متعارضة.
3. **Replacement:** انتقال مصرح به يستبدل التصريح الحالي كوحدة ذات معنى، يحتفظ بالسابق، ويرفع revision مرة عند تغيير مادي. لا يعيد كتابة evidence أو history. التوصية القديمة تحتاج إعادة تقييم مقابل السياق الجديد؛ الاستبدال ليس إلغاءً تلقائيًا لإجراء خارجي أو سياسة تشغيل.
4. **Clearing/removal:** يصبح `NOT_DECLARED / UNKNOWN`، لا تصريحًا تجاريًا بأن «لا هدف لنا»، ولا zero target، ولا نية مستنتجة من الحملات. يسجل الانتقال إن كان تغييرًا حقيقيًا؛ clear مطابق لحالة غير معلنة هو no-op مصرح به.

لا status enum أو active flags متعددة أو lifecycle transitions مضاربة. الإصدار يميز تغير السياق، وليس وعدًا بتحقق النية. متى يبدأ تنفيذ إجراء ومتى تنتهي مراقبته مسألة Action/Observation لاحقة.

## G. Authority / version / concurrency — answers to questions 1–6

| سؤال | إجابة مستندة إلى المصدر |
| --- | --- |
| 1. Business-level fact؟ | YES. المطلوب تغيير تجاري لهذا النشاط، مستقل عن account/campaign/user؛ يستهلك نوع النشاط وأسواقه ويغير تفسير قراراته. |
| 2. Workspace physical identity؟ | YES مع Business Context semantic owner. هوية النشاط ومسار UUID والعزل موجودة؛ لا contradiction يستدعي هوية Business موازية. |
| 3. الاشتراك في `business_context_version`؟ | YES كتوصية للنية المعلنة. |
| 4. لماذا semantically correct؟ | اسم/نوع/أسواق/نية حقائق business-level معلنة تشكل السياق المستهلك للقرار. تعديل النية قد يبطل تفسير قرار كما يفعل تعديل السوق؛ الإصدار المشترك يكتشف اختلاف السياق. |
| 5. بديل authority/version قائم أدق؟ | لا. source/access/observation generations تملك مصادر وأدلة؛ commercial configuration revision يملك سياقًا تجاريًا مختلفًا؛ لا يملك أي منها تصريح المعلن المطلوب. |
| 6. Goal-specific revision؟ | غير مبرر لأول slice؛ سيضيف مجال stale-write مستقلًا مع ضرورة التحقق أيضًا من business context. لا يزيل الحاجة إلى consistency المشتركة. |

**Lock order الفعلي:** `WorkspaceContext::runBusinessMutation` → signed principal + فحص العضوية → `builderad_business_context_begin` → `pg_advisory_xact_lock(hashtextextended('builderad.business.context.' || workspace_id, 0))` → `builderad_select_workspace_context` وأقفال SHARE الحالية للهوية/النشاط/العضوية/المالك → `builderad_source_actor` → ختم prepared مربوط بالسياق الموقّع → writer يتحقق من prepared → actor → Workspace `FOR UPDATE` → أقفال الحقيقة/grant ذات الصلة → إعادة authority check على `clock_timestamp()` → no-op أو conflict → mutation + event + revision → تحقق authority بعد الأثر/الانتظار → commit transaction.

البوابة الصحيحة تبدأ من principal phase **قبل** دخول selector العادي. هذا مهم: لا يكفي استدعاء writer مستقبلي داخل `WorkspaceContext::run`، لأن الـSHARE locks قد تؤخذ قبل serialization. لا تُعدل shared actor helper ولا lock ordering في هذه المهمة.

**المخاطر المادية المحددة:**

- revision مشترك يعني أن مسودة النية قد تتعارض بعد حفظ النوع/الأسواق والعكس؛ هذا رفض محافظ مقصود، لا lost update. المصدر الحالي يعالج مسودات name/type مقابل DTM في `Context.vue` و`TargetMarketsEditor.vue`. يجب ألا يسمح السطح المستقبلي بإعادة baseline لمسودة تغيرت دون مراجعة.
- استخدام entry أو advisory key مختلف يعيد احتمال SHARE→UPDATE upgrade contention الذي عالجه الإصلاح. تسلسل mutation الموجود قابل للاستهلاك، لكنه لا يثبت أي writer جديد قبل اختبار مختلط معه.
- authority قبل انتظار فقط غير كافية: اختبارات expiry/revocation/lifecycle/member/owner موجودة؛ يجب الحفاظ على effect-time checks ورفض `40P01`، لا إخفاؤه بإعادة محاولة عامة.
- قراءة حقائق السياق في عدة statements لا تثبت وحدها snapshot قرار متسق تحت كل isolation level. لا يُفترض أن مجرد وجود رقم revision يجعل evidence/decision ذرّية؛ يحتاج consumer المستقبلي إثبات سياق القراءة وإعادة تقييمه قبل action.
- تغيير معنى outcome declaration المؤثر في السياق يجب ألا يمر صامتًا خارج consistency/history المستهلكة للقرار. لا يُلزم هذا بإلحاق observation runs بالإصدار؛ تغير الأدلة يظل مصدرًا مستقلًا.

لا يوجد تناقض مادي مع authority أو shared revision، ولا حاجة إلى إصلاح Foundation في هذا الاستكشاف.

## UX discovery — interaction bounded to the business

المسار الموجود: business selection/creation → الاسم والنوع → `Business/Sources`، مع رابط «سياق النشاط» في `AdvertiserLayout`. صفحة `Business/Context` تجمع name/type مع محرر DTM، و`EnsureBusinessContext` يشترط الاسم والنوع فقط. لا تضاف بوابة Intent إلزامية أو navigation جديدة في هذه المهمة.

| التفاعل المرشح | التقييم |
| --- | --- |
| selection فقط | منخفض الحمل لكنه قد يخفي معنى «مؤهل» ويفرض taxonomy لم تثبت؛ غير كافٍ وحده للمثال. |
| selection + conditional fields | مناسب إن أثبت التصميم لاحقًا بدائل محدودة ذات معنى؛ تظهر تفاصيل النتيجة والمقدار/المهلة عند الحاجة، دون تثبيت قائمة شاملة الآن. |
| guided short form | المرشح الأول: «ما الذي تريد تحقيقه الآن؟»، ثم توضيح ما يُحسب نتيجة، مع مقدار/مهلة اختياريين حين ينطبقان؛ عرض ملخص التصريح قبل الحفظ. |
| free text فقط | يفيد الوصف لكنه لا يثبت outcome meaning أو قابلية المقارنة؛ لا يُستخدم أساسًا وحيدًا للقرار ولا يُحوَّل إلى معنى inferred دون تأكيد المعلن. |

التوصية تفاعل قصير داخل سياق النشاط القائم، بلغة عربية طبيعية وRTL، مع progressive disclosure وحالة غير معلنة واضحة وread-only لغير المخوّل ومراجعة صريحة عند conflict. يبدأ بما يريد المعلن تحقيقه ومعناه، ثم التفاصيل اللازمة؛ لا حقول provider/configuration أو Goal management داخل التدفق. لم يُصمم أو يُنفذ UI، ولم يُجرَ اختبار بصري جديد.

## H. First vertical enablement

**هل الحد الأدنى كافٍ لبدء تصميم قرار واحد؟ YES على مستوى الدلالات، بشرط إظهار تبعيات الأدلة والقيود دون اختراعها. هل الحالة الحالية كافية للتنفيذ؟ NO.**

| جزء المثال | ما يجعل bounded reallocation ذا معنى | حالة المصدر / المالك |
| --- | --- | --- |
| Real Estate + Saudi Arabia | سياق business/market معلن، دون افتراض qualification خاص بالعقارات | Business Type وDTM موجودان؛ مكان نشاط الشركة ليس مطلوبًا. |
| Acquire qualified buyers | توجيه القرار نحو اكتساب نتيجة أعمال مؤهلة، لا تعظيم clicks أو leads | Current Intent مفقود؛ يلزم تصريح المعلن. |
| Qualified buyer | معنى اقتصادي ومعيار قبول واضحان مشتركان في مقارنة Google A وMeta B | Desired Outcome Meaning ودليل القبول مفقودان؛ لا تستنتج الشروط من المثال. |
| 100 / 30 days | مقدار وموعد يغيران pace والجدوى والاستعجال حين يُعلنان؛ ليسا شرطًا لكل reallocation نسبي | مرشحا Intent مشروطان؛ لا defaults ولا خلط مع report window. |
| 5,000 SAR | سقف مصرح به مع currency ونطاق وفترة وأثره على حجم نقل الإنفاق | shared Spend constraint مفقود؛ لا يصبح campaign budget أو commercial amount. |
| Google A أقوى من Meta B | دليل outcomes مقبول تجاه نفس المعنى، campaign/source attribution وحدود زمن/coverage قابلة للمقارنة؛ missing ليس zero | observations موجودة، لكن Google conversion source-native فقط، وMeta reader لا يجلب conversions؛ مقدمات المثال غير مثبتة. |
| نقل مبلغ بين الحملتين | budget/configuration الفعلية، destination capacity والقيود، execution authority/action contract، ثم observation/verification | تبعيات شريحة مستقبلية غير منفذة؛ لا تدخل في Intent ولا تؤذن بهذا النقل. |

اختيار قناة بسبب عدد تحويلات Google مقابل clicks أو spend Meta وحده لا يثبت decision delta المطلوب. يجب أن يظل مسار القرار المستقبلي قادرًا على الإقرار بأن الأدلة غير كافية. الكمية والموعد والسقف تحسن أو تقيد القرار حسب حالته؛ غيابها لا يمنح صلاحية execution مفتوحة.

التسلسل محفوظ: Goal/Intent محدود → اختيار قرار اقتصادي واحد → أقل Targeting × Spend + evidence + synthesis + action + observation/verification يغلق حلقة واحدة. لا Targeting engine كامل ثم Spend engine كامل ثم دمج مؤجل. العقارات والسعودية مثال، وليستا نموذج Goal دائمًا.

## I. Deferred items

- أي migrations/models/tables/controllers/routes/APIs/Vue/dependencies أو تعديل Business Context/DTM/providers/authority/locks/revision في هذه المهمة.
- specification أو implementation plan؛ التنفيذ يتطلب Owner review/design authorization لاحقًا.
- Goal management/OKR/hierarchy/templates/strategy/funnel/KPI dashboard أو generic Intent/Goal/lifecycle/context framework.
- Outcome System، qualification/conversion engines، generic ontology، CRM/commerce/SDK وأي تكامل جديد.
- Targeting/Spend/Synthesis/Action/Verification/Learning engines، أو تنفيذ bounded reallocation المثال الآن.
- Market Evidence ingestion/adapters/Opportunity Engine، REGA/DataForSEO/Similarweb/Semrush/GWI/YouGov/Lucidya/Statista؛ تظل GOVERNED، NOT IMPLEMENTED.
- live-provider research أو اتصال primary أو migrations عليها، source-control publish، أو لمس old DTM worktree/main.

## J. Material risks / contradictions

1. **الفجوة الأهم: عدم وجود معنى وقياس مقبولين للنتيجة الاقتصادية.** Intent label لا يجعل Google conversions مشترين مؤهلين؛ وMeta الحالي لا يقدم دليلًا متناظرًا. يمنع ذلك تنفيذ المثال، لا استكشاف أو تصميم Goal المحدود.
2. **ملكية outcome غير منفذة.** الحوكمة تحدد فصل المعاني، ولا تحدد تنفيذ مالك قائم يُستبدل. توصية A تمنع duplication مستقبلية؛ يلزم تثبيت هذا الحد في التصميم الذي يراجعه المالك، دون Outcome System الآن.
3. **تفاوت history protections.** DTM وprovider observation لديهما immutable triggers؛ name/type events لديهما حفظ وصلاحيات runtime مقيدة لكن دون trigger مماثل ظاهر. ادعاء أن كل Business Context history له نفس الضمان سيكون غير دقيق. لم يُصلح شيء ولم يُفتح audit شامل.
4. **Shared revision/lock integration.** أي writer مستقبلي يحتاج نفس prepared entry وauthority/no-op/conflict/audit؛ وجود البنية الحالية لا يثبت صحة امتداده تلقائيًا.
5. **Budget/time collisions.** operational read budget أو reporting period أو commercial denomination قد يبدو مناسبًا لكنه لا يصرّح بقيد موارد أو مهلة أعمال؛ الاستعارة تخلق حقيقة مزيفة.

**STOP-CONDITION ASSESSMENT:** لم يُعثر على canonical Goal قائم، أو Outcome owner منفذ متناقض، أو مالك سلطة مختلف، أو سبب يجعل Goal منتج إدارة مستقلًا. لا blocker يستلزم تغيير اتجاه المالك قبل تسليم الاستكشاف. تحديات دليل التنفيذ الموضحة أعلاه تظل تبعيات للشريحة المستقبلية.

## Required final ruling

```text
CURRENT_GOAL_INTENT_STATE = MISSING
EXISTING_GOAL_CONCEPT = NO
PROVIDER_OBJECTIVE_COLLISION_RISK = MEDIUM
MINIMUM_OUTCOME_MEANING_REQUIRED = YES
GOAL_MANAGEMENT_PRODUCT_REQUIRED = NO
QUANTITY_UNIVERSALLY_REQUIRED = NO
TIME_HORIZON_UNIVERSALLY_REQUIRED = NO
BUDGET_CONSTRAINT_BELONGS_TO_GOAL = CONDITIONAL
ONE_CURRENT_INTENT_SUFFICIENT_FOR_FIRST_SLICE = YES
EXISTING_BUSINESS_CONTEXT_AUTHORITY_REUSABLE = YES
EXISTING_SHARED_REVISION_REUSABLE = YES
NEW_GENERIC_INTENT_FRAMEWORK_REQUIRED = NO
OUTCOME_SYSTEM_IMPLEMENTATION_REQUIRED_NOW = NO
MARKET_IMPLEMENTATION_REQUIRED_NOW = NO
NEW_INTEGRATION_REQUIRED_NOW = NO
READY_FOR_BOUNDED_GOAL_DESIGN = YES
BLOCKERS = NONE for bounded Goal design after Owner review; accepted goal-aligned outcome evidence and bounded execution contracts are missing for the future executable slice
NEXT_RECOMMENDED_STEP = Owner review of this discovery, then authorize one bounded semantic Goal design that consumes independently owned Desired Outcome Meaning
```

`MEDIUM` يصف خطر الخلط في المصدر الحالي: provider objectives غير ممثلة صراحة، والتحويلات الحالية محمية بتوصيف source-native، لكن تحويلات Google ولفظ intent التشغيلي قد يُعاد استخدامهما خطأً. يتصاعد الخطر إذا تجاهل التصميم تلك الحدود. `CONDITIONAL` لا يضع سقف 5,000 SAR العام داخل Goal؛ يسمح فقط بقيد خاص بمعنى التغيير نفسه عندما يثبت اختلافه عن shared Spend constraint.

**سجل سلامة المهمة:** documentation artifact واحد فقط؛ production edits = 0؛ primary connections/writes = 0؛ migrations/test DB runs = 0؛ external/provider calls = 0؛ commit/push/merge = NO؛ task-owned servers/watchers/browser helpers/background processes started = 0. فحص اكتمال التقرير ومراجع المصدر وحصر التغييرات تحقق ثابت، وليس runtime regression gate.

**التحقق الثابت الفعلي:** 61 مرجع ملف/سطر موجود وضمن حدود الملف؛ أقسام A–J كاملة؛ مفاتيح الحكم المطلوبة 18/18؛ trailing whitespace = 0؛ `git diff --check` ناجح؛ `git status --short` يحصر التغيير في هذا التقرير غير المتعقب؛ HEAD والفرع باقيان مطابقين للمرجع. لا يُستنتج من ذلك نجاح اختبارات runtime لم تُشغّل.
