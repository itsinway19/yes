# Current Intent — bounded semantic design 001

**2026-10-04 · Europe/Istanbul · تصميم دلالي مكتمل، بانتظار مراجعة المالك.**

هذه الوثيقة تصمم العقد الدلالي المحدود فقط. لا تفوّض التنفيذ أو تخطيطه، ولا تحدد schema أو migrations أو API أو mechanism للتاريخ. المصطلح canonical هو **Current Intent**؛ «Goal / الهدف» اختصار واجهة لنفس المفهوم، دون ملكية أو lifecycle ثانٍ.

## 1. Owner decisions / scope

توجيه المالك **BOUNDED SEMANTIC DESIGN 001** يغلق الاستكشاف ويقبل نتائجه، ثم يوضح ما يلي بما يتقدم على أي صياغة متعارضة في تقريره:

- **CURRENT INTENT IS A DISTINCT SEMANTIC CONTRACT.** مالك معنى النية هو Current Intent Contract، وليس Business Context.
- Workspace هو الهوية الفيزيائية؛ `business.context.manage` سلطة الكتابة القائمة؛ بوابة mutation القائمة هي حد serialization؛ `business_context_version` token اتساق مشترك. اشتراك هذه الآليات لا ينقل الملكية الدلالية.
- Desired Outcome Meaning مستقل دلاليًا عن النية وعن Observed Outcome؛ الاستقلال لا يوجب subsystem أو entity أو table أو API مستقلًا.
- نية حالية واحدة كحد أقصى لكل Workspace؛ العدد والمهلة اختياريان بحسب المعنى؛ budget عام خارج العقد؛ التاريخ مطلوب دلاليًا دون حسم آليته.
- النشاط بلا نية يظل صالحًا. لا defaults أو استنتاج صامت من نوع النشاط أو أسواقه أو أدلة المزوّدين.

تقرير `docs/current-goal-intent-discovery-001.md` محفوظ كما هو، بما في ذلك توصيته التاريخية بملكية Business Context للنية. هذه الوثيقة **تصحح ذلك وفق قرار المالك** ولا تعيد كتابة التقرير لإخفائه. لا تسري عبارة «إعادة استخدام Business Context» هنا على ملكية المعنى.

الناتج المصرح به الوحيد لهذه المرحلة هو هذه الوثيقة؛ لا implementation plan، ولا code أو tests جديدة، ولا commit/push/merge.

**Owner correction 001:** التصميم مقبول جوهريًا، وهذه النسخة تعالج F1–F5 فقط: اتجاه التغيير القابل للاستهلاك، ونطاق التصريح، واتساق projection، وتأكيد المنطقة الزمنية، والتوضيح التدريجي لمعنى النجاح. تبقى قرارات الملكية والسلطة والتاريخ والإصدار وحدود التنفيذ المقبولة قائمة دون إعادة تصميم أو إعادة Discovery.

## 2. Current system dependency baseline

| حد قائم | الدليل المباشر | دلالته للتصميم |
| --- | --- | --- |
| HEAD / branch / worktree | `2cf51ea337b26e07bfcaff59ed9d3e81c632a50e`؛ `codex/current-goal-intent-discovery-001`؛ `C:\dev\builderad-worktrees\current-goal-intent-discovery-001` | يطابق baseline الاستكشاف؛ التقرير هو التغيير الموجود عند بداية هذه المرحلة. |
| هوية وعزل النشاط | `app/Models/Workspace.php`؛ `app/Foundation/Tenancy/WorkspaceContext.php:32`، `runBusinessMutation` | لا business identity موازية أو resolver جديد. |
| التصريحات الحالية وإصدارها | `app/Business/BusinessContext.php:47`؛ `database/sql/business_context.sql:11`؛ `database/sql/declared_target_markets.sql:40` | الاسم/النوع وDTM قائمون؛ Current Intent وDesired Outcome Meaning غير منفذين. |
| بوابة كتابة متسلسلة | `database/sql/business_context_lock_order.sql:3`، `builderad_business_context_begin`؛ `:27`، `builderad_business_context_require_prepared` | serialized entry قبل selector، مع signed context وإعادة تحقق السلطة بعد الانتظار. |
| no-op / conflict / audit | `database/sql/business_context_lock_order.sql:61`؛ `database/sql/declared_target_markets.sql:68`؛ `tests/Feature/BusinessContextLockOrderTest.php` | التطابق المصرح به no-op؛ اختلاف مادي stale يفشل بـ`40001`؛ real change يرفع revision مرة. |
| proof boundaries موجودة | `tests/Feature/DeclaredTargetMarketsConcurrencyTest.php`؛ `tests/Feature/DeclaredTargetMarketsSecurityTest.php` | أمثلة لمتطلبات إثبات مستقبلية، وليست اختبارات Current Intent ناجحة. |
| تجربة المعلن الحالية | `resources/js/Pages/Business/Context.vue`؛ `resources/js/Components/Business/TargetMarketsEditor.vue`؛ `resources/js/Layouts/AdvertiserLayout.vue`؛ `app/Http/Middleware/EnsureBusinessContext.php` | تفاعل ضمن السياق القائم؛ الاسم/النوع فقط شرط السياق الحالي، ولا يضاف Intent gate لإنشاء النشاط. |
| حدود أدلة المصدر | `app/Providers/Ads/AdObservation.php:8`؛ `app/Providers/Ads/Google/GoogleObservationAdapter.php:170` | observations ليست نتائج أعمال مقبولة؛ Google conversion source-native، وMeta لا يوفر حاليًا دليلًا متناظرًا لqualified buyers. |

فُحصت هذه الحدود قراءةً فقط. مصدر الاستكشاف يحفظ البحث الأشمل. لم تُشغّل قاعدة/مزوّدًا أو regression gate في هذه المرحلة، ولم تُفتح مراجعة جديدة لقرارات Foundation أو DTM المستقرة.

## 3. Canonical Current Intent definition

**Current Intent هو التصريح الحالي من المعلن أو مدير النشاط المخوّل، بالنتيجة التجارية أو التغيير التجاري المادي الذي يطلب مساعدة Builderad لتحقيقه الآن.**

العقد يملك **التغيير المطلوب** وكونه التصريح الحالي، ومقداره/مهلته إن انطبقا. يستهلك معنى نتيجة أو قبول يوضح ما الذي يمثل خدمة هذا التغيير. لا يملك الحقيقة المرصودة ولا يدعي أن التصريح تحقق أو أن الأدلة كافية أو أن التنفيذ مسموح.

مثل «زيادة المشترين المؤهلين»، «حماية النتائج المجدية»، «تقليل الهدر مع الحفاظ على الطلبات ذات القيمة»، «التحقق من جدوى اتجاه جديد» أمثلة تفسيرية؛ ليست enum أو catalog أو Goal taxonomy. لا يتحول آخر مثال إلى Market Opportunity أو خطة تجربة داخل Intent.

**الحد الأدنى عند إعلان النية:** تغيير مقصود واضح، ومعنى نتيجة/قبول مرتبط به يؤكده المعلن. لا يكفي «أريد نتائج أفضل» أو «مشتري مؤهل» دون توضيح لما يغير قبول النتيجة. العدد أو التاريخ لا يعوضان غياب هذا المعنى.

لا يعد النص الخام machine-interpreted truth. التصريح canonical هو **حزمة معنى مقسمة ومؤكدة**: اتجاه التغيير الصريح المحدود أدناه، ونطاق النشاط كله، وما الذي يُعد نتيجة ذات صلة، وما معيار قبول الصفة المؤثرة إن وجدت؛ ثم مقدار وموعد اختياريان. يبقى وصف المعلن المقروء منفصلًا عن دلالة الاتجاه؛ تأكيد prose وحده لا يحولها إلى اتجاه قابل للاستهلاك.

### 3.1 F1 — bounded advertiser-confirmed change direction

**الاختيار A:** علاقة تغيير محدودة يؤكدها المعلن صراحةً تجاه معنى النتيجة/القبول، بدل أن يستنتجها المستهلك من النص. في هذه الشريحة يثبت التصريح اتجاهًا واحدًا من الدلالات الأربع التالية؛ ليست enum تقنية مقررة، أو taxonomy عامة لكل أهداف الأعمال، ولا تصنيفًا يُجرى على النص عند القراءة.

| دلالة الاتجاه المؤكدة | ما يستطيع consumer تمييزه دون تفسير prose | Decision delta الذي يبررها |
| --- | --- | --- |
| زيادة النتيجة ذات القيمة | المطلوب المزيد من النتيجة المحددة، وليس مجرد إبقائها | يفرق قرارًا يخدم acquisition/increase عن الاكتفاء بأداء قائم؛ هذا اتجاه مثال المشترين في أول اختبار دلالي. |
| الحفاظ على النتيجة ذات القيمة | المطلوب حمايتها من تدهور مادي بحسب معنى القبول المؤكد | زيادة عدد آخر مع الإضرار بالنتيجة المحمية لا تكون نجاحًا بسبب العدد وحده. |
| تحسين الكفاءة مع الحفاظ على القيمة | التحسين المطلوب في الكفاءة مشروط بالحفاظ على النتيجة ذات القيمة | خفض الإنفاق مع إضاعة القيمة لا يحقق هذا الاتجاه؛ لا تستنتج منه budget/allocation أو threshold. |
| التحقق من اتجاه أعمال محدود المعنى | المطلوب دليل تجاري مؤكد المعنى على جدوى الاتجاه، لا تعظيم نتيجة مفترضة ثبتت جدواها | يفرق التحقق من فرضية أعمال عن توسيعها؛ لا يحدد experiment أو stop policy أو action. |

هذه أصغر فروق يحتاج المستهلك معرفتها حتى لا يعتبر «increase X» و«protect X» و«efficiency preserving X» أو «validate» متكافئة لمجرد ذكر X. الربط بالنتيجة يحدد ما يتعلق به الاتجاه؛ تفاصيل acceptance تبقى ملكًا لمعنى النتيجة، لا engine داخل اتجاه التغيير. لا أوزان أو أولويات أو اتجاهات متزامنة أو operator DSL.

المعلن يختار/يؤكد الدلالة المعروضة بلغة مفهومة، ولا يُختار اتجاه افتراضي من الوصف أو Business Type أو evidence. المستهلك يقرأ تلك الدلالة الصريحة؛ لا NLP/LLM reinterpretation أو intent-classification engine. وصف المعلن يشرح التصريح، ولا يغير الاتجاه المؤكد بصمت. إذا كانت نية مطلوبة خارج هذه الفروق أو متعارضة معها، يُطلب توضيح صريح أو يؤجل دعمها دون إسقاطها في أقرب فئة. لا يعد النص وحده PRESENT قابلًا للاستهلاك، ولا يتحول غياب التصريح إلى UNKNOWN direction داخل نية مزعومة.

وجود اتجاه مؤكد لا يعني وجود decision consumer منفذ يدعم كل اتجاه، ولا يكفي لقبول observed outcomes أو تشغيل إجراء. مستهلك لا يدعم الدلالة المعلنة يتوقف عن تقديم ذلك القرار بدل تخمين دلالة أخرى. تاريخ التصريح يحفظ الاتجاه المؤكد نفسه؛ تغييره مادي حتى لو بقي outcome meaning والعدد/الموعد والوصف كما هي. لا يجعل الاحتفاظ بنفس وصف النتيجة الاتجاهين متساويين.

### 3.2 F2 — declaration scope: BUSINESS_WIDE_ONLY

**الاختيار A:** العقد الأول يدعم النية على **النشاط كله ممثلًا بهذا Workspace** فقط، بتأكيد صريح من المعلن. ليست scope مستنتجة من كون Workspace الهوية الفيزيائية. «هذا التصريح ينطبق على النشاط كله» ملك Current Intent؛ ملكية النشاط أو خدمة/مشروع/سوق تحته لا تنتقل إليه.

لا sub-subject reference أو Product/Service/Project/Offer catalog أو generic Subject framework في هذه الشريحة. لا حاجة إلى إعادة فحص واسع للمستودع لتصميم مالك غير مستخدم في هذا النطاق. دعم التصريح الأضيق لاحقًا يتطلب أولًا حقيقة canonical ذات هوية ومالك واضحين، ثم boundary applicability يراجعها المالك؛ لا تُنشأ تلك التبعية هنا ولا يعد وجود اسم نصي لها تحقيقًا لها.

«30 حجزًا للزرعات فقط»، «100 مشترٍ للمشروع A»، و«التحقق من اكتساب العملاء في الإمارات فقط» تصريحات أضيق **تُرفض/تؤجل بوضوح** في العقد الأول، لا تُحفظ بوصفها whole-business أو يختفي قيدها من الملخص. لا يقبل نص ينص على حصر كهذا مع ملخص يوسعه للنشاط كله. يُعرض الحد للمعلن ليقرر تصريحًا مختلفًا يريده فعلًا على مستوى النشاط أو يترك النية غير معلنة؛ لا إعادة تفسير آلية أو تغيير نية حالية أثناء رفض المسودة.

وجود السعودية والإمارات في DTM لا يختار نطاق النية، ولا يعني أن النية عامة أو تخص دولة منهما تلقائيًا. ذكر السوق كموضوع التصريح مختلف عن Targeting configuration وMarket Opportunity، لكن ذلك لا يبرر دعم narrow market scope في هذه الشريحة. Targeting/Audience/Campaign scopes تبقى خارج Intent.

في الاختبار العقاري، 100 مشترٍ مؤهل يعني هدفًا للنشاط كله بعد تأكيد ذلك؛ إن قصد المعلن Project A وحده فالمثال خارج دعم الشريحة الأولى. «validate a direction» هنا تحقق لاتجاه الأعمال على مستوى النشاط كله، لا تصريحًا بسوق/عرض جزئي؛ دعم الاتجاه الدلالي لا يوسع دعم subject scope.

## 4. Semantic ownership map

| المعنى | مالكه الدلالي | العلاقة بالعقد |
| --- | --- | --- |
| intended business change / currentness / target / declared business horizon | Current Intent Contract | يملك التصريح الحالي والانتقالات بين تصريحاته. |
| business result and acceptance meaning | Desired Outcome Meaning، منفصل عن Current Intent | قيمة معنوية يستهلكها Intent؛ تأكيد تغييرها مستقل وواضح حتى إن حُفظت مع النية في تفاعل واحد. |
| الاسم والنوع وDTM | ملاك حقائق Business Context القائمون | سياق قرار مجاور؛ لا يستنتج intent منه، ولا يُستبدل بهذه الوثيقة. |
| Workspace identity / membership / session / grants | Foundation authority القائمة | تحمل الهوية والصلاحية؛ لا تحدد ما يريد المعلن تحقيقه. |
| shared consistency token / mutation serialization | الآليات القائمة باسم Business Context | تجمع حقائق معلنة تغير تفسير القرار؛ لا تعني ملكية جميع تلك المعاني. |
| observed evidence / provider objective and configuration | المصدر/عقد الدليل ذي الصلة | يمكن أن يساهم لاحقًا في القراءة؛ لا يعلن Intent أو acceptance نيابة عن المعلن. |
| shared advertising resources / allocation | shared Spend/resource context المستقبلية / Spend | خارج Current Intent. |
| execution authority / automation / verification windows | Action/Execution/Observation contracts المستقبلية | لا يمنح التصريح سلطة فعل خارجي. |

لا إعادة تسمية لـFoundation، ولا تغيير لملكية DTM، ولا permission جديد للتعبير عن هذا الفصل.

## 5. Desired Outcome Meaning boundary

### 5.1 الحد الأدنى المحفوظ

معنى النتيجة هنا معنى تجاري معلن ومؤكد، مع أقل توضيح يغير ما يُحسب تجاه النية. يمكن أن يكون تأكيدًا بسيطًا لمعنى booking الواضح في سياق النشاط، ولا يلزم سؤال تعريف رسمي لكل معلن. يحفظ وصفًا تجاريًا للنتيجة، ومعيار قبول محدودًا فقط عند وجود صفة مؤثرة تحتاج توضيحًا، والوحدة الدلالية إن كان هناك عدد. لا تُملأ معايير «مؤهل» تلقائيًا ولا تشتق من Real Estate أو DTM. بيان «ما الذي يجعلها تُحسب كذلك؟» يُستخدم عند الحاجة، لا كعبء ontology إلزامي.

«شخص أبدى اهتمامًا» لا يساوي «مشتريًا مؤهلًا». إذا قصد المعلن بالثاني شروطًا إضافية مثل ملاءمة القدرة الشرائية وجدية طلب الشراء، فلا تعد هذه شروطًا canonical إلا بعد تصريح المعلن وتأكيده لها؛ المثال ليس قاعدة عقارية عامة. provider event باسم lead أو conversion أو purchase لا يثبت أيًا من هذه الشروط.

لا تتطلب كل نية نتيجة قابلة للعد:

| النية | أقل معنى مطلوب للتقدم | ما لا يفرضه العقد |
| --- | --- | --- |
| اكتساب/زيادة X | ما الذي يُحسب X؛ الصفة المؤثرة ووحدة العد عند وجود target | قيمة مالية أو conversion event مفروض |
| خفض الهدر | ما النتيجة القيمة التي ينبغي الحفاظ عليها أثناء تحسين كفاءة استخدامها للإنفاق | عدد مصطنع أو allocation أو حساب كفاءة منفذ |
| حماية الأداء المجدي | ما النتيجة ذات القيمة، وما التدهور الذي يعد ماديًا في سياق هذا التصريح | baseline مرصود مختلق أو threshold افتراضي |
| التحقق من اتجاه | ما الدليل التجاري الذي يعد تقدمًا معتبرًا أو قبولًا مبدئيًا لهذا التحقق | عدد مبيعات نهائي موحد أو experiment/action policy |

هذه الفروق أوصاف للتفاعل، وليست أنواعًا canonical جديدة. يمكن الاحتفاظ ببيان قبول نوعي. إن ظل البيان غامضًا، لا يدعي consumer لاحق قابلية الحسم؛ يطلب التوضيح/الدليل اللازم. اكتمال التصريح لا يثبت قابلية قياسه آليًا.

### 5.2 Representation ruling: confirmed value, no reusable persistent identity

التمثيل الأول المختار هو **قيمة معنى كاملة مؤكدة مرتبطة بالتصريح الحالي**، مع حفظ معناها الكامل ضمن التاريخ اللازم. لا outcome ID مستقل أو catalog أو registry أو Outcome-specific revision في هذا التصميم.

قورنت ثلاثة اتجاهات: (أ) القيمة المؤكدة مع حفظ المعنى الكامل، وهو المختار؛ (ب) هوية Outcome قابلة لإعادة الاستخدام، والتي تضيف قواعد إدارة وربط لم تثبت الحاجة إليها؛ (ج) label حر فقط، الذي يفقد acceptance meaning وحماية التاريخ. استقلال المالك الدلالي ليس سببًا كافيًا لاختيار (ب).

**لماذا لا تحتاج هوية مستقلة الآن؟** هناك مستهلك current واحد لكل Workspace؛ عند الانتقال من increase X إلى protect X أو improve efficiency of X، يمكن حمل **نفس قيمة outcome meaning المؤكدة دون تغيير**. القرارات التاريخية تحتاج المعنى الذي استهلكته، لا lookup إلى أحدث تعريف قابل للتعديل. قيمة محفوظة ذات معنى كامل تحقق ذلك دون entity مشتركة مستقلة.

**حماية التاريخ:** الانتقال يحتفظ بالمعنى السابق كاملًا. المرجع التاريخي الصحيح هو Workspace + shared revision الذي استُهلك، مع إمكانية استرجاع أو الاحتفاظ بالمعنى الفعلي عنده؛ ليس label أو current-value pointer. رقم الإصدار وحده دون المعنى القابل للاسترجاع غير كافٍ. لا تبني هذه المهمة Decision entity أو عقد حفظ القرارات.

**استقلال التغيير:** تعديل intended change أو العدد/الموعد يحمل outcome meaning السابق كما هو؛ لا يسمح بتعديل acceptance ضمنيًا. إذا أراد المعلن تغيير وصف النتيجة أو معيارها، يعرض التفاعل ذلك كتغيير معنى مستقل للتأكيد، مع before/after واضحين. يمكن تأكيد النية والمعنى الجديد معًا وحفظهما ذريًا، دون endpoint أو service مستقلين. هذا استهلاك صريح لعقد منفصل، لا استحواذ Intent على qualification semantics.

Material outcome change يشترك في `business_context_version`، لأنه قد يغير قبول الأدلة وتفسير target والقرار حتى مع بقاء intended change نفسه. لا تُلحق provider observations بهذا الإصدار. Clear يزيل ارتباط outcome الحالي بالنية الغائبة، ويحتفظ بمعناه في التاريخ؛ لا يقرر حذف Outcome entity لأن مثل هذا الكيان غير مفروض أصلًا.

## 6. Current-value semantics

لكل Workspace **صفر أو نية حالية واحدة**:

- **PRESENT:** يوجد اتجاه تغيير محدود يؤكده المعلن ونطاق business-wide مؤكد، ومعنى outcome/acceptance المرتبط به، مع optional quantity/horizon الصحيحين إن وُجدا.
- **NOT_DECLARED / UNKNOWN:** لا تصريح حالي يمكن استخدامه لتفسير قرار intent-dependent. لا نميز في أول contract بين «لم يعلن» و«مسح التصريح» كحالتين current مختلفتين؛ التاريخ يبين الانتقال إن حدث.

هذه معاني وجود/غياب، وليست stored status enum أو lifecycle. المسودة غير المؤكدة لا تصبح current. النية التاريخية لا تصبح current عند clear، ولا يُستخدم provider objective fallback، ولا يعني الغياب zero results أو «المعلن يريد لا شيء».

يمكن للنشاط الإنشاء والتصفح والربط وقراءة السياق بلا Intent؛ لا يتغير شرط الاسم/النوع القائم. قبل قرار لا يفهم بأمان دون intent، يوضح consumer حاجته إلى التصريح. ذلك شرط للقرار المحدد، لا شرط لصلاحية النشاط أو access جديد.

## 7. Quantity semantics

العدد **اختياري وغير مطلوب عالميًا**. في أول contract، إن اختير، يمثل **عددًا مستهدفًا موجبًا صحيحًا من وحدات نتيجة الأعمال المعلنة**؛ مثال 100 مشترٍ مؤهل أو 20 حجزًا. لا كميات سالبة/كسرية، ولا صفر يعبر عن absence، ولا money/percentage/score كمقياس عام في هذا العقد الأول.

لا يقبل عدد بلا وحدة مرتبطة بـDesired Outcome Meaning. «100» لا يكفي، ولا `Google conversions = 100` وحدة بديلة. التصريح العددي لا يسجل observed count ولا progress؛ لا baseline أو actual total أو نسبة إنجاز داخل Intent.

الغياب يعني **لا مقدار مستهدف معلن**، لا zero ولا unbounded ولا default مستنتج. Reduce waste/protect/validate لا تحتاج عددًا مصطنعًا؛ إذا عبّر معلن عن كمية في نتيجة قابلة للعد داخل تلك النية، تُقبل فقط عند بيان معناها الفعلي، لا بسبب اسم النية.

إذا تغير معنى النتيجة أو وحدتها، لا يُحمَل العدد السابق كهدف للمعنى الجديد تلقائيًا؛ يؤكد المعلن ملاءمته أو يزيله. إضافة/حذف/تغيير target تغير التزام التصريح وقرار الجدوى، وبالتالي تغيير مادي. تنسيق `100` بصورة محلية لا يغير العدد أو الوحدة.

## 8. Time-horizon semantics

المهلة **اختيارية وغير مطلوبة عالميًا**. التمثيل الدلالي الأول الوحيد هو **موعد نهائي تجاري مؤكد، محدد بيوم محلي ومنطقة زمنية معلومة**. معناه «أريد تحقيق هذا التغيير بحلول نهاية هذا اليوم في هذه المنطقة». لا range أو recurrence أو scheduling system أو countdown lifecycle.

هذا الاختيار يستوعب «خلال 30 يومًا» دون موعد ينزلق مع retries: يعرض التفاعل اليوم النهائي الناتج من anchor واضح ويؤكده المعلن **قبل الحفظ**. إذا استُخدم اختصار مدة، فهي عدد أيام تقويمية من يوم البداية المعروض: 30 يومًا يعني إضافة 30 يومًا تقويميًا لذلك اليوم، لا 30 فترة ثابتة من 24 ساعة. التصريح المقبول هو الموعد النهائي الملموس؛ المدة ليست representation ثانية يتعين إدارتها.

اليوم النهائي يشمل اليوم المحلي كله؛ الحد الزمني المقابل هو بداية اليوم المحلي التالي كحد نهاية غير شامل. المنطقة الزمنية جزء من تفسيره، وتحتاج **تأكيدًا صريحًا** مع الموعد. أي اقتراح منطقة هو مساعدة عرض فقط حتى يؤكده المعلن؛ لا توريث ضمني من provider account أو server/host أو browser أو IP أو Commercial Market أو DTM، ولا منطقة Workspace مفترضة. بعد التأكيد يبقى تفسير المهلة ثابتًا؛ لا يعيد أي تغيير لاحق في إعداد عرض أو مصدر زمن تفسيرها دون تصريح جديد.

**F4 — ما أثبته الفحص المحدود:** يوجد عمود `workspaces.timezone` بـdefault `UTC` في `database/migrations/0001_01_01_000010_create_workspace_foundation.php:19`، والإنشاء يكتب `UTC` في `database/migrations/0001_01_01_000060_create_workspace_authority.php:97`؛ يظهر أيضًا ضمن Fillable في `app/Models/Workspace.php:10`. هذا يثبت وجود storage attribute، ولا يثبت معنى canonical business timezone أعلنه المعلن لآفاق أعماله. حد إنشاء/تحرير Business Context المفحوص لا يطلب تأكيدًا له. لذلك `CANONICAL_WORKSPACE_TIMEZONE_PROVEN = NO` لهذا الغرض؛ أزيل افتراض «منطقة Workspace الحالية» كمصدر موثوق. لا timezone subsystem جديد.

no-op أو retry أو تعديل quantity لا يعيد حساب anchor/«30 يومًا من الآن». الموعد نفسه يبقى ما لم يغيّره المعلن صراحةً. اختيار 30 يومًا من تاريخ جديد قد يخلق موعدًا مختلفًا، وهو تغيير مادي حتى إن ظل النص «30 يومًا» نفسه.

الغياب يعني **لا مهلة تحقيق معلنة**، لا فورًا أو دائمًا أو date مشتقًا من التقرير. مرور الموعد لا يمسح النية ولا يغير revision/حالتها تلقائيًا؛ يوضح العرض أن الموعد مضى وتحتاج ملاءمة القرار إلى مراجعة. لا يعد مرور الزمن transition معلنًا أو execution stop policy. وقت التغيير المسجل يظل audit/change time، لا business deadline.

## 9. Excluded budget / resource semantics

«موارد الإعلانات المتاحة للنشاط 5,000 SAR» shared Spend/resource constraint يخص قرارات متعددة؛ **لا يملك Current Intent ذلك القيد**. كذلك campaign budget، allocations، provider funding instruments، actual spend، currency التجارية، request budgets ليست جزءًا من النية.

«التحقق من اتجاه دون التعرض لخسارة تتجاوز X» قد يكون intent-specific bound إذا كان جزءًا حقيقيًا من التغيير المقصود، لا نسخة من ميزانية عامة. **لا يتضمنه العقد الأول كعنصر canonical**: لم يُثبت هنا decision delta يتطلب تمثيله قبل اختيار القرار المستقبلي. ظهوره في شرح المعلن لا يتحول تلقائيًا إلى spend cap أو guardrail قابل للتنفيذ. consumer لا يختزل التصريح أو يتجاهل القيد؛ يتوقف عن افتراض قابلية تنفيذ ذلك الجزء ويطلب التحديد المناسب لاحقًا.

لم تُصمم shared Spend contract أو risk framework. استبعاد عنصر من العقد الأول لا يمنح spending authority مفتوحة.

## 10. Replacement / clear / bounded history

| العملية الدلالية | الأثر المقبول |
| --- | --- |
| Create | انتقال NOT_DECLARED إلى PRESENT بعد تأكيد حزمة المعنى، ذريًا مع revision/history. |
| Replace | تصريح جديد يصبح الحالي كوحدة مترابطة؛ السابق محفوظ بكل ما يلزم تفسيره. |
| Modify | تعديل جزء صريح من التصريح الحالي، مع الاحتفاظ بالأجزاء غير المتغيرة؛ الناتج قيمة حالية كاملة متسقة، دون lifecycle ثانٍ. |
| Clear | انتقال PRESENT إلى NOT_DECLARED / UNKNOWN؛ history يحفظ معنى التصريح الذي أُزيل. clear لحالة غائبة مطابقة no-op بعد authorization. |

Modify/Replace لا يعيدان كتابة provider evidence أو observed outcomes أو القرارات الماضية. لا يلغيان تنفيذًا خارجيًا تلقائيًا؛ استهلاك تصريح قديم في قرار جديد يتطلب إعادة تقييم بالسياق الحالي. كل تغيير مادي واحد مع تأكيداته المشتركة يرفع shared revision **مرة واحدة فقط**، لا مرة للنية ومرة للمعنى إذا حُفظا كعملية ذرية واحدة.

### المعلومات التي يجب أن تبقى محفوظة

| المعلومة | لماذا يحتاجها تفسير المستقبل |
| --- | --- |
| Workspace + الحالة السابقة واللاحقة | معرفة النشاط وما إذا كان التصريح موجودًا أو غائبًا؛ التمييز بين clear وتبديل المعنى. |
| اتجاه intended change السابق والجديد المؤكدان ووصف التصريح المرتبط بهما | يمنع إظهار قرار acquisition قديم وكأنه صدر تجاه protection جديد؛ لا استخراج اتجاه تاريخي من prose لاحقًا. |
| applicability scope السابق والجديد المؤكدان | يثبت أن التصريح انطبق على النشاط كله؛ لا يعاد تفسير تاريخ business-wide كنطاق مشروع أو سوق أضيق مستقبلًا. |
| outcome/acceptance meaning الكامل المستخدم قبل/بعد + وحدة count إن انطبقت | يثبت ما كان يُحسب نتيجة؛ لا live pointer قابل لإعادة التعريف ولا Outcome ID مستقل مطلوب. |
| quantity وغيابها، horizon بتفسيره الزمني وغيابه | target/deadline يغيران معنى التصريح؛ unknown مختلف عن zero وعن deadline جديد. |
| الممثل البشري المخوّل وتوقيت الانتقال الفعلي | إسناد من أعلن التغيير ومتى أصبح نافذًا؛ ليس evidence أن النتيجة تحققت. |
| shared consistency revision قبل/بعد الانتقال | ربط المعنى بسياق القرار واكتشاف stale consumers، دون Goal/Outcome revision. |

الوقت النافذ لهذه النسخة هو **وقت قبول الانتقال المصرح به في المعاملة التي تلتزم**؛ لا future effective date أو backdating. يجب أن يكون الاسترجاع من التاريخ قادرًا على حماية معنى النسخة حتى بعد clear وإعادة الإعلان. عودة A بعد A→B ليست no-op اعتمادًا على وجود A في التاريخ؛ تقارن العملية بالقيمة **الحالية** وتحتفظ بالانتقال.

هذا ضمان semantic/audit بعدم إسقاط التاريخ أو تغييره بصمت. لا حسم لـevent sourcing أو trigger أو dedicated history table أو temporal DB أو UI timeline. اختلاف حماية سجلات name/type عن DTM المذكور في الاستكشاف لا يوجب repair في هذه المهمة، ولا يبرر ضمانًا أضعف لتاريخ النية المستقبلية.

## 11. Semantic equality / authorized no-op

المساواة معناها: نفس **دلالة اتجاه التغيير المؤكدة** ونفس business-wide applicability، ونفس التغيير المقصود المؤكد ووصفه المحفوظ، ونفس outcome/acceptance meaning ووحدة العد، ونفس وجود/قيمة quantity، ونفس وجود/تفسير الموعد النهائي. absent يساوي absent. لا تعتمد على label أو رقم target أو revision وحده. زيادة X وحماية X غير متساويين حتى إذا تطابق وصف X والعدد والموعد؛ لا تُستنتج مساواة الاتجاه من النص.

**حد المعرفة:** لا يستطيع النظام إثبات تساوي نصين حريْن من تشابه الصياغة. لذلك يعتمد التصميم على guided confirmed components، ويستخدم دليل مساواة محافظًا: إبقاء المكونات canonical المؤكدة نفسها أو إعادة تقديمها دون تغيير مضمونها، مع مساواة العدد/الوحدة والموعد بتفسيره. المطابقة هنا تقارن التصريح المؤكد المقسّم، لا نصًا واحدًا غير مؤكد أو model inference.

التغييرات العرضية المسموحة لا تعدّل المحتوى canonical: التفاف السطر، نمط العرض، ترقيم/تنسيق العدد، مسافات حول النص أو فواصل مسافات لا تزيل كلمات أو قيودًا. لا حذف علامات نفي أو modifiers، ولا ترتيب جديد لجمل acceptance، ولا synonym/stemming/Arabic-letter folding أو ترجمة تلقائية تدخل في دليل المساواة. labels الواجهة المترجمة ليست تغييرًا في تصريح المعلن.

إذا غُيّرت الكلمات الجوهرية، ولو ادعى الكاتب أنها paraphrase، **لا تُثبت المساواة تلقائيًا ولا تُمنح stale no-op**. يعرض التفاعل التصريح المؤكد السابق: يمكن الاحتفاظ به دون تغيير، أو اعتماد صياغة canonical جديدة كتغيير يحتاج إصدارًا حديثًا وتاريخًا. هذا احتياط لحماية معنى التصريح، لا ادعاء أن كل مرادفين مختلفان في الواقع. لا توجد «same meaning» علامة يرسلها العميل فتتجاوز المقارنة، ولا NLP equality engine.

| الحالة | السلوك |
| --- | --- |
| authorized + القيمة المطلوبة مثبتة المساواة بالقيمة الحالية، حتى مع expected revision قديم | no-op؛ يرجع السياق/الإصدار الحالي، دون تغيير revision/history/effective time أو تمديد الموعد. لا يقر بأن consumer القديم ما زال صالحًا. |
| authorized + قيمة مختلفة أو مساواتها غير مثبتة + expected revision حالي | transition مادي مؤكّد كامل، أو رفض التصريح غير المكتمل؛ لا silent merge. |
| authorized + قيمة مختلفة أو غير مثبتة المساواة + expected revision قديم | stale conflict وفق `40001` القائم؛ مراجعة السياق مطلوبة. |
| unauthorized، بما فيه طلب مطابق | denied؛ التطابق لا يتجاوز authority. |

القبول يعتمد على المقارنة مع **الواقع المتسلسل الحالي** لكل المكونات المطلوبة، لا فقط baseline المسودة. لو تغير outcome meaning بينما احتفظ طلب قديم بالنية نفسها، فالحزمة لم تعد مطابقة؛ يحظر استخدام partial update لتمرير quantity مرتبطة بمعنى قديم على معنى جديد دون مراجعة.

## 12. Authority

تُعاد الاستفادة من `business.context.manage` في Workspace نفسه؛ لا `goal.manage` أو model authority جديد. يحفظ الحد signed human advertiser/session، current lifecycle/generation، active membership، Workspace active، responsible owner صالح حيث يطلبه المصدر القائم، وصلاحيات view/mutate الدقيقة ذات expiry/revocation.

غير المالك صاحب الصلاحية الدقيقة يستطيع الإعلان والتعديل والمسح. المالك بلا الصلاحية الدقيقة لا يستطيع ذلك تلقائيًا. staff/provider/service authority أو read access لا تعلن نية المعلن؛ لا اعتماد على provider callback أو observation worker أو service context لتوليدها.

تأكيد معنى النتيجة المعلن يستخدم الحد البشري نفسه؛ ذلك لا يمنح authority لقبول observed outcomes. اختيار UI أو عرض `canManage` ليس سلطة بديلة عن التحقق الحالي عند الأثر. يجب إعادة التحقق بعد الانتظار الملائم وقبل قبول الأثر، بما فيه no-op؛ تطابق القيم لا يعيد إحياء جلسة أو منحة منتهية/مسحوبة.

## 13. Shared revision / consistency

`business_context_version` هو **consistency token للتصريحات business/decision-level التي قد تغير تفسير القرار**، وليس إثبات ملكية Business Context لمعنى Current Intent.

Create/Replace/Modify/Clear المادي، أو تغيير outcome meaning المرتبط بالنية، يشارك في هذا token مع name/type وDTM. لذلك writer stale يقترح تغييرًا مختلفًا يتعارض حتى لو كان التغيير السابق في مفهوم آخر. no-op المصرح به يبقى no-op حين يثبت تطابق حزمة الطلب مع current value، ولا يمنح صلاحية إعادة استخدام قراءة قديمة للسياق.

لا Goal-specific أو Outcome-specific revision أو parallel stale-write domain. التاريخ يستطيع تحديد shared revision آخر انتقال للنية، لكنه لا يخلق عدادًا جديدًا. تغيّر الوقت أو report runs أو observations لا يزيد إصدار التصريحات من تلقاء نفسه.

لا يحول token قراءات متعددة تلقائيًا إلى snapshot واحد. المستهلك المستقبلي يجب أن يعرف أي تصريح ومعنى outcome وسياق مشترك استهلك، وألا يستخدم آخر قيمة live لإعادة تفسير نتيجة تاريخية. لا تُصمم هنا read protocol أو Action revalidation، لكن لا تُدعى consistency تحققها لمجرد عرض version.

### 13.1 F3 — coherent read projection

أي Current Intent projection للواجهة أو مستهلك القرار يجب أن يعرض **حالة semantic ملتزمة واحدة متماسكة**: direction المؤكد، declaration subject/scope، Desired Outcome Meaning، quantity/horizon وغيابهما، presence/absence، والوصف المرتبط بها، مع shared consistency revision الخاصة بالحالة نفسها. لا direction من قبل transition مع outcome/target أو revision من بعده. PRESENT لا يحتوي أجزاء من نسخة cleared، وNOT_DECLARED لا يعرض outcome/quantity/horizon من التصريح الماضي كأنها حالية.

يمكن أن تكون القراءة أقدم من آخر commit، لكن يجب أن تكون متماسكة ومعرّفة بإصدارها الحقيقي؛ coherent لا يعني latest أو authorization للكتابة/التنفيذ. لا يصح تصنيع projection تبدو current باستبدال رقم إصدارها فقط. إن تعذر ضمان التماسك لا تُسلّم الحزمة المختلطة بوصفها تصريحًا صالحًا للاستهلاك. هذه correctness requirement؛ لا اختيار SQL shape أو isolation level أو query count أو transaction/ORM mechanism هنا.

## 14. Lock-order / mutation-boundary and mixed concurrency requirements

متطلبات القبول لأي تنفيذ لاحق، دون تعديل SQL الآن:

- نفس `WorkspaceContext::runBusinessMutation` / prepared mutation entry، ونفس البوابة `builderad_business_context_begin`، قبل selector-first SHARE locks؛ لا إدخال writer جديد عبر `run` العادي ثم محاولة upgrade غير متسلسلة.
- نفس serialization القائمة لكل Workspace؛ لا advisory-lock namespace ثانٍ أو lock-order جديد، ولا تعديل shared actor helper لهذا العقد.
- تحقق prepared evidence والسلطة الحالية بعد waits المناسبة؛ atomic mutation + shared revision + bounded history/audit، rollback للأثر كله عند الفشل.
- authorized same semantic value no-op؛ stale material different conflict؛ لا lost update أو partial meaning/quantity transition أو silent rebase.
- `40P01` failure، ولا retry يخفيه أو lock timeout مقبول بوصفه نجاحًا.

| التداخل المطلوب إثباته لاحقًا | النتيجة الدلالية المنتظرة |
| --- | --- |
| Intent × Intent — قيمة واحدة | انتقال واحد كحد أقصى، revision/history مرة واحدة، الآخر authorized no-op بالقيمة الحالية. |
| Intent × Intent — قيمتان مختلفتان من baseline واحد | فائز كامل، الآخر stale conflict؛ لا خليط بين change/outcome/quantity/horizon. |
| Intent × name/type | نفس consistency/serialization؛ تغيير مختلف stale يتعارض، ولا يمحو writer حقيقة الآخر؛ إثبات كلا ترتيبي الفوز. |
| Intent × DTM | نفس ما سبق مع حفظ مجموعة الأسواق كاملة وملكية DTM؛ إثبات كلا ترتيبي الفوز. |
| Desired Outcome Meaning × Intent | إذا عدّل أحدهما acceptance بينما الآخر يبدل change أو quantity، فلا تلحق قيمة قديمة بمعنى جديد بصمت؛ authorized equal no-op أو stale material conflict وفق current bundle. |
| expiry/revocation/lifecycle/membership/owner change during wait | denied/rollback أو linearization المقبولة القائمة إذا جاءت revocation بعد writer مصرح به؛ لا أثر غير مخوّل. |

«Desired Outcome Meaning × Intent» عمليتان دلاليتان متداخلتان على الحزمة المشتركة؛ لا يستلزم ذلك كيان outcome مستقلًا أو مسار API مستقلًا. قبول تعديل المعنى والنية معًا كتغيير واحد يتطلب تأكيد كلا التغييرين ومعاملة واحدة؛ لا يُختبر هنا أي تنفيذ.

## 15. UX contract

مقطع قصير داخل تجربة «سياق النشاط» الموجودة، لا Goals navigation أو dashboard أو strategy center. العربية أولًا، RTL صحيح، يعرض الإجابة/ملخص التصريح الحالي قبل تفاصيل تحريره؛ read-only يرى المعنى والعدد/الموعد عند وجودهما دون أدوات كتابة.

**عند الغياب:** «لم تحدد ما تريد تحقيقه الآن» مع إجراء للمخوّل «تحديد ما أريد تحقيقه». لا صياغة «ليس لديك هدف» ولا منع إنشاء النشاط أو استخدامه.

**تفاعل واحد متدرج، دون wizard إلزامي:**

1. «ما الذي تريد تحقيقه الآن؟» — يؤكد المعلن اتجاهًا محدودًا بلغة طبيعية: زيادة نتيجة مهمة، الحفاظ عليها، تحسين الكفاءة مع الحفاظ على القيمة، أو التحقق من جدوى اتجاه أعمال. يمكنه إرفاق وصف قصير؛ لا اتجاه افتراضي أو تصنيف صامت للوصف. يظهر نطاق «على مستوى النشاط كله» ويؤكَّد، مع توضيح أن حصر التصريح في خدمة/مشروع/سوق معين غير مدعوم حاليًا. هذه ليست قائمة أهداف عامة أو اختيار provider.
2. **F5 — التوضيح الأدنى عند الحاجة:** يعرض معنى النتيجة في جزء مستقل ويطلب تأكيده. «زيادة الحجوزات» قد يكفي معها تأكيد بسيط للحجز المقصود إذا كان واضحًا بالفعل؛ لا إنشاء تعريف جديد لمجرد استيفاء form. «المشترون المؤهلون» تستدعي سؤالًا قصيرًا عن الصفة التي تغير القبول إن لم تكن مؤكدة بعد. «تقليل الهدر» يحتاج تحديد النتيجة القيمة التي ستُحفظ، دون عدد مصطنع. «التحقق من اتجاه» يحتاج معنى دليل تجاري معتبر، دون experiment design أو stop/Action policy. لا يطلب تفاصيل لا تغير القبول، ويمكن إبقاء معنى النتيجة السابق كما هو دون إعادة كتابته عند تعديل النية.
3. «هل تريد تحديد عدد؟» و«هل لديك موعد تريد تحقيق ذلك بحلوله؟» يظهران عند انطباق المعنى، باختيار صريح، دون required/default. quantity يعرض وحدته؛ الموعد يعرض اليوم والمنطقة وتفسير أي اختصار مدة.
4. ملخص المؤكد: «أريد [اتجاه التغيير] [النتيجة/الاتجاه المقصود] على مستوى النشاط كله»، مع وصف المعلن إن أضافه، ومعنى النتيجة والصفة المؤثرة عند الحاجة، ثم العدد/الموعد والمنطقة المؤكدة إن وُجدت. المعلن يراجع ويؤكد قبل الحفظ. تغيير acceptance يُعرض منفصلًا عن تغيير النية، ولو حُفظا بإجراء واحد. لا تبدو المسودة الأضيق business-wide بسبب إسقاط الكلمات التي قيدتها.

Free text يساعد بيان المعنى ضمن هذه الأجزاء؛ لا يُحلّل بصمت إلى canonical intent/type أو acceptance rules. لا اقتراح آلي يتحول إلى تصريح بمجرد كتابته أو مطابقته لBusiness Type. البيان الذي لا يوضح outcome/acceptance المطلوب يعاد للمعلن لتوضيحه دون افتراض من المصدر. يمكن عرض معنى نوعي مؤكد؛ لا ادعاء أن التأكيد صنع engine قادرًا على تأهيل الأحداث.

**عند التحرير:** يبدأ بالقيمة المؤكدة نفسها، لا إعادة توليد صياغتها أو مهلة «30 يومًا» من تاريخ جديد. تأكيد حذف quantity/horizon صريح في الملخص. «مسح التصريح الحالي» يوضح أن التاريخ يبقى وأن النشاط يظل صالحًا؛ لا يمسح تنفيذًا خارجيًا.

**عند shared revision change:** clean view يمكن تحديثه؛ dirty draft يبقى مع baseline الأصلي دون silent rebase، سواء تغيّر name/type أو DTM أو معنى النتيجة. الرسالة: «تغيّر سياق النشاط. راجع الحالة الحالية ومسودتك قبل الحفظ». يعرض المعنى الحالي والمسودة ويتيح المراجعة أو التخلص من المسودة؛ قبول حفظ جديد بعد المراجعة ليس retry آليًا. نجاح stale no-op يعرض الإصدار الحالي ولا يدعي اعتماد قرار سابق.

لا مصطلحات semantic owner/lock/revision في رحلة المستخدم العادية. إن كانت لديه نية لا يمكن تمثيل قيد أساسي فيها ضمن هذا العقد الأول، لا تُحفظ نسخة منقوصة تبدو معتمدة لاتخاذ فعل؛ يوضح التفاعل الجزء الذي يحتاج تحديدًا خارج النطاق.

### First-vertical semantic check

«نشاط عقاري؛ السوق المعلن السعودية؛ اتجاه معلن مؤكد: زيادة النتيجة ذات القيمة عبر اكتساب مشترين مؤهلين؛ التصريح على مستوى النشاط كله؛ معنى المشتري المؤهل كما أكده المعلن؛ العدد اختياري 100؛ الموعد والمنطقة المؤكدان المقابلان لاختيار 30 يومًا» تصريح coherent. إن قصد Project A فقط فهو غير مدعوم ولا يُوسَّع صامتًا. يُفصل عنه shared 5,000 SAR، Google A/Meta B، الحملة، التخصيص، audience والتفويض التنفيذي.

يقدم التصميم المعاني المعلنة اللازمة ليسأل consumer لاحق: «أي قرار targeting/spend يخدم هذه النية؟». لا يصمم ذلك القرار ولا يزعم أن current provider evidence يستطيع الإجابة: qualified-outcome acceptance/attribution/comparability ودليل التنفيذ ما زالت تبعيات مستقلة غير منفذة.

## 16. Acceptance matrix

هذه حالات قبول للتصميم ولإثبات تنفيذ لاحق، وليست tests منفذة أو خطة كتابة اختبارات.

| Case | السيناريو | القبول الملزم |
| --- | --- | --- |
| A | نشاط قائم بلا نية | صالح؛ NOT_DECLARED/UNKNOWN؛ لا onboarding gate/default. |
| B | campaign objective أو conversions/spend موجودة | لا inferred Current Intent أو fallback لتصريح تاريخي. |
| C | تصريح حماية نتيجة قيمة بلا target/deadline | PRESENT إذا كان change ومعنى القبول واضحين ومؤكدين؛ لا كمية/موعد مصطنعين. |
| D | 100 مشترٍ مؤهل | العدد موجب صحيح، ووحدته النتيجة المؤكدة؛ target لا observed count. |
| E | مهلة تجارية ذات decision delta | deadline محلي مع منطقة مؤكدة؛ لا campaign/report/verification window. |
| F | 5,000 SAR موارد عامة | خارج Current Intent؛ لا تحويلها إلى Goal field أو spending authority. |
| G | Google provider conversion | لا يصبح Desired Outcome Meaning أو qualified-buyer evidence تلقائيًا. |
| H | replace acquisition بـprotection | الجديد current؛ القديم وoutcome/target/deadline/history قابل للاسترجاع؛ لا evidence rewrite أو automatic cancellation. |
| I | clear | NOT_DECLARED/UNKNOWN؛ لا «أريد لا شيء»، لا zero ولا fallback؛ history محفوظ. |
| J | authorized replacement مثبت المساواة، حتى stale | no-op بعد authority؛ current shared revision يُعاد؛ لا history/time/quantity/deadline effect. |
| K | materially different stale replacement | `40001`؛ لا partial effect أو retry silent rebase. |
| L | name/type أو DTM تغيّر مع Intent write | shared token وبوابة واحدة؛ materially different stale conflict؛ كلا ترتيبي الفوز محفوظان دون lost update/40P01. |
| M | intended change يُعدّل مع acceptance سابق | outcome value يُحمل كما هو؛ أي تعديل له يحتاج تأكيدًا مستقلًا واضحًا وshared revision/history ذريين. |
| N | service/provider/staff path أو owner بلا exact grant | لا declaration/mutation؛ signed authorized non-owner manager مسموح وفق السلطة القائمة. |
| O | مستخدم يملك القراءة دون mutation | يفهم التصريح الحالي؛ لا create/replace/modify/clear أو outcome edit. |
| P | dirty draft بعد unrelated shared change | لا استبدال baseline/تأكيد تلقائي؛ يحتفظ بالمسودة ويطلب مراجعة current context. |
| Q | موعد مضى أو نية استُبدلت | لا status/lifecycle framework؛ مرور الوقت لا transition تلقائيًا؛ الاستبدال current-value transition فقط. |
| R | channel/audience/campaign/allocation/opportunity | خارج ملكية Intent؛ لا تتحول إلى canonical slots لتحسين سهولة الشريحة. |
| S | no-op مطابق مع expiry/revocation أثناء wait | authority denial/rollback؛ التطابق لا يمنح حقًا ولا يخفي `40P01`. |
| T | retry «30 يومًا» أو تعديل target فقط | يبقى deadline الملموس؛ لا anchor reset أو تمديد خفي؛ اختيار deadline جديد transition. |
| U | outcome meaning تغير مع بقاء target 100 أو تغير quantity بالتزامن | إعادة تأكيد target تجاه المعنى الجديد؛ stale conflicts؛ لا إلحاق target القديمة بمعنى جديد بصمت. |
| V | paraphrase/label مشابه أو A→B→A | لا equality inferred؛ يحتفظ بالمؤكد أو يعتمد تغييرًا جديدًا بإصدار حديث؛ المقارنة مع الحالي لا التاريخ. |
| W | نفس outcome meaning مع اتجاه زيادة مقابل حماية أو كفاءة مقابل تحقق | direction معلن صريح يميزهما؛ اختلافه مادي ولا no-op بسبب mention النتيجة نفسها؛ لا read-time prose inference. |
| X | نشاط متعدد الخدمات/العروض/المشروعات، أو تصريح لدولة واحدة ضمن DTM متعددة | العقد BUSINESS_WIDE_ONLY يتطلب تأكيدًا؛ التصريح الأضيق مثل الزرعات/Project A/الإمارات فقط يرفض/يؤجل بوضوح دون توسيعه أو اختراع subject owner. |
| Y | قراءة تتداخل مع transition للنية أو outcome أو clear | direction/scope/outcome/quantity/horizon/presence والوصف والإصدار تعرض committed semantic state واحدة؛ mixed projection غير مقبولة ولو بدا version حديثًا. |
| Z | منطقة زمنية مقترحة أو UTC مخزنة أو provider/server/browser/IP/market timezone | لا horizon بلا تفسير زمني مؤكد؛ الاقتراح فقط presentation حتى يؤكد؛ لا Workspace business timezone مفترضة. |
| AA | booking واضح مقابل qualified modifier أو preservation/validation يحتاج توضيحًا | تأكيد بسيط يكفي للمعنى الواضح؛ توضيح إضافي فقط لما يغير acceptance؛ لا formal definition إلزامي أو عدد/experiment/Action semantics مصطنعة. |

## 17. Deferred concepts

Targeting implementation؛ Spend implementation وshared Spend/resource contract implementation؛ Decision Synthesis؛ Action/Execution Contract؛ ADVISE/PREPARE implementation؛ Observation/Verification؛ Learning؛ Market Evidence/Opportunity implementation؛ REGA/DataForSEO/Similarweb/Semrush/GWI/YouGov/Lucidya/Statista ingestion؛ commerce integrations؛ CRM؛ Builderad SDK؛ qualification/attribution/deduplication engines؛ generic Outcome System/catalog/ontology/value/revenue/KPI engine؛ generic Goal/Intent/lifecycle framework؛ multi-goal priorities/hierarchy/management؛ campaigns-as-goals؛ history timeline UI.

التصميم المحدود لمعنى النتيجة في القسم 5 لا يعد تصميم Outcome System. التاريخ هنا requirement لا storage architecture؛ مثال القرار semantic test لا vertical design.

## 18. Risks / unresolved questions

**Material unresolved semantic questions: NONE within this bounded design.** اختيرت حدود المعنى والملكية والغياب والعدد والمهلة والتاريخ والمساواة والاتساق؛ لا تتطلب stop condition جديدة أو authority/framework إضافيًا.

F1 محسوم باتجاه معلن محدود قابل للاستهلاك دون قراءة prose؛ F2 محسوم بتقييد صريح BUSINESS_WIDE_ONLY. لا يدعي هذان الحكمان دعم كل نية أو subject مستقبلي. F3 correctness requirement للقراءة؛ F4 يميز عمود UTC عن منطقة أعمال مؤكدة؛ F5 يجعل clarification مشروطًا بالقبول، لا سؤال ontology دائمًا.

| خطر حقيقي | الحد الذي يحمي التصميم |
| --- | --- |
| confirmed prose يظل أقل من qualification engine | يحفظ تصريح المعلن ولا يزعم حسم قبول observed evidence؛ consumer يصرح بعدم كفاية المعنى/الدليل بدل اختراع rules. |
| conservative equality قد يسجل paraphrase كتغيير تصريح | اختيار مقصود لتجنب stale false no-op؛ يمكن الاحتفاظ بالمضمون المؤكد نفسه، ولا يوجد semantic deduplication. |
| outcome meaning مضمن في الحزمة قد يُفسر ownership merge | مكونات مستقلة، confirmation مستقل لتغيير outcome، وقيمة قابلة للحمل دون تغيير عند تعديل intended change؛ لا ownership inference من persistence. |
| shared revision يخلق conflicts بين مفاهيم مختلفة | رفض محافظ متوافق مع العقد القائم؛ UX يحفظ dirty drafts ولا يعيد baseline صامتًا. |
| تاريخ بلا آلية مثبتة أو consumer يعتمد current lookup | requirement المعنى الكامل والاسترجاع بإصدار السياق إلزامي؛ آلية التخزين/proof تحدد بعد قبول التصميم، دون وعد بأن source الحالي يحققها للنية. |
| horizon anchor/zone يتغيران في retry أو locale | الموعد المحلي وتفسيره ثابتان ومؤكدان؛ locale عرض فقط، لا reset. |
| أدلة first vertical غير كافية | يمنع ادعاء جاهزية التنفيذ؛ لا يمتص Intent spend/targeting/action facts لتعويض النقص. |

أمور تقنية متروكة عمدًا للمرحلة اللاحقة: storage/layout، protection mechanism للتاريخ، signatures/payloads، حدود أطوال الإدخال، read snapshot/proof protocol، تنظيم ملفات UI/SQL/tests. ليست أسئلة ملكية دلالية معلقة ولا تفويضًا لتخطيطها الآن.

## 19. Explicit implementation boundaries / verification record

لم يُنشأ أو يُعدل إنتاجيًا: migrations/tables/SQL writers/PHP/routes/controllers/Vue/APIs/provider adapters/authority/lock ordering/Business Context/DTM/dependencies. لا tests جديدة أو implementation plan أو external calls أو primary database connection أو migration run أو Targeting/Spend/Market work.

المتوقع في worktree: تقرير الاستكشاف القائم + هذه الوثيقة فقط. SHA256 تقرير الاستكشاف عند دخول المرحلة: `CBE50B60606362CA0861B17458CD87FCC6E28A76E90D404EFD7BCAE330F69FD5`. يجب أن يبقى مطابقًا عند التسليم. لم تبدأ المهمة dev server/watcher/browser helper أو background process؛ لا عمليات مهمة تتطلب إبقاءها.

المراجعة الذاتية المطلوبة: اكتمال الأقسام 1–20، حالات A–V المقبولة وإضافات التصحيح W–AA فقط، أحكام المالك النهائية، وفصل الملكية عن البنية؛ التحقق من scope/preserved discovery والمراجع وwhitespace، دون تقديم ذلك كruntime verification. الوثيقة المصححة تنتظر مراجعة المالك؛ قبول النسخة السابقة جوهريًا لا يفوض تخطيط التنفيذ قبل مراجعة التصحيح.

**حد تحقق التصحيح:** مقارنة هذه الوثيقة بنسخة ما قبل التصحيح، والتحقق من الحالات W–AA وأحكام F1–F5، وبقاء SHA256 الاستكشاف مطابقًا لبصمة الدخول، وعدم تغير ملفات الإنتاج أو ظهور plan/document جديد. لا runtime tests شُغّلت ولا ادعاء بنجاحها.

**نتيجة التحقق الفعلية للتصحيح:** الأقسام 20/20؛ حالات القبول 27/27، مع بقاء A–V دون تغيير؛ أحكام التصحيح المطلوبة 11/11 ضمن 37 حكمًا نهائيًا؛ مراجع ملفات/أسطر 21/21 صالحة؛ placeholders وtrailing whitespace = 0. مقارنة النسخة السابقة تثبت أن التعديلات محصورة في F1–F5 وآثارها الدلالية والأحكام/التحقق؛ الأقسام 2 و4 و7 و9 و12 و14 و17 باقية حرفيًا. بصمة الاستكشاف مطابقة، وHEAD/branch مطابقان للمرجع، وscope الملفات يظل تقرير الاستكشاف القائم ووثيقة التصميم نفسها فقط. لا production changes أو implementation plan أو commit/push/merge أو primary connection.

## 20. Final design ruling

```text
DESIGN_PHASE = COMPLETE
CURRENT_INTENT_SEMANTIC_OWNER = CURRENT_INTENT_CONTRACT
PHYSICAL_IDENTITY = WORKSPACE
BUSINESS_CONTEXT_SEMANTIC_OWNER_OF_CURRENT_INTENT = NO
BUSINESS_CONTEXT_AUTHORITY_REUSED = YES
EXISTING_MUTATION_SERIALIZATION_REUSED = YES
SHARED_BUSINESS_CONTEXT_REVISION_REUSED = YES
GOAL_SPECIFIC_REVISION_CREATED = NO
ONE_CURRENT_INTENT = YES
CLEAR_SEMANTICS = NOT_DECLARED / UNKNOWN
BOUNDED_HISTORY_REQUIRED = YES
HISTORY_MECHANISM_PREDECIDED = NO
QUANTITY_UNIVERSALLY_REQUIRED = NO
TIME_HORIZON_UNIVERSALLY_REQUIRED = NO
SHARED_SPEND_CONSTRAINT_OWNED_BY_GOAL = NO
DESIRED_OUTCOME_MEANING_SEMANTIC_OWNER = SEPARATE_FROM_CURRENT_INTENT
DESIRED_OUTCOME_SEPARATE_SUBSYSTEM_REQUIRED = NO
PROVIDER_OBJECTIVE_CAN_DEFINE_CURRENT_INTENT = NO
PROVIDER_CONVERSION_CAN_DEFINE_DESIRED_OUTCOME = NO
TARGETING_SPEND_FACTS_INSIDE_CURRENT_INTENT = NO
GOAL_MANAGEMENT_PRODUCT = NO
OUTCOME_SYSTEM_IMPLEMENTED_OR_DESIGNED = NO
MARKET_IMPLEMENTATION_INCLUDED = NO
NEW_INTEGRATION_INCLUDED = NO
ACCEPTANCE_MATRIX_COMPLETE = YES
INTENDED_CHANGE_MACHINE_SEMANTICS = RESOLVED
INTENDED_CHANGE_REPRESENTATION = BOUNDED_ADVERTISER_CONFIRMED_CHANGE_DIRECTION; increase value / preserve value / improve efficiency preserving value / validate a business direction; prose is descriptive, no inferred direction
INTENT_SUBJECT_SCOPE = BUSINESS_WIDE_ONLY
INTENT_OWNS_UNDERLYING_BUSINESS_SUBJECT = NO
COHERENT_READ_PROJECTION_REQUIRED = YES
CANONICAL_WORKSPACE_TIMEZONE_PROVEN = NO
HORIZON_TIMEZONE_REQUIRES_EXPLICIT_CONFIRMATION = YES
SUCCESS_MEANING_CLARIFICATION = CONDITIONAL_AND_MINIMAL
MATERIAL_UNRESOLVED_SEMANTIC_QUESTIONS = NONE
READY_FOR_OWNER_DESIGN_REVIEW = YES
READY_FOR_IMPLEMENTATION_PLAN = NO
NEXT_RECOMMENDED_STEP = Owner review of corrected bounded design
```

**نهاية المرحلة:** مراجعة المالك للتصميم أولًا. لا انتقال تلقائي إلى implementation planning حتى يقبل المالك هذه الوثيقة صراحةً، ولا تنفيذ بتفويض هذه المرحلة.
