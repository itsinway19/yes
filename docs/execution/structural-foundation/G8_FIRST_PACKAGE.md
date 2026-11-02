# U01-K01 — Qualified Shared-Knowledge Workbench Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for authorized inline implementation. The current task authorizes qualification only. Do not implement before the package-specific Owner envelope below is accepted.

**Goal:** تمكين المشغّل البشري المخوّل من اختيار أدلة سوقية مؤهلة، وإعداد معالجة مشتركة محددة ثم طلب نشرها عبر مسارات D3 القائمة، دون استعمال UUID/JSON يدوي أو صلاحية مالك قاعدة البيانات.

**Architecture:** توسيع U01 بسطح عمل داخلي منفصل عن إسقاط المراقبة الآمن. يعاد استعمال capture/publish وطرق D3 الأربعة وسلطة staff والحقوق لكل مصدر/غرض/حقل والإكمال الموثوق. يضاف قارئ خيارات ضيق مؤهل حاليًا؛ لا سجل حقيقة أو محرك أو grants جديدة.

**Tech Stack:** Laravel / PostgreSQL / native Vue SPA / existing typed OpenAPI. الآليات الحالية كافية؛ لا مبرر لخدمة أو لغة أو مزود جديد لهذا العقد.

**Spec:** [برنامج البناء المقبول v1.2](PROGRAM.md)، [سجل التفويض](OWNER_MANDATE.md)، ودلالة D3 القائمة في `docs/execution/market-knowledge/PROGRESS.md` مع الشيفرة المدمجة عند `c747f6d8a8f2011fa5dcc368da2aa4e8449e0565`.

## 1. نتيجة G8 وحدودها

**مرشح تشغيل مستقل:** `U01-K01 / Qualified Shared-Knowledge Workbench`.

**النتيجة:** `DEFERRED / NON_BLOCKING_IN_CURRENT_CONSTRUCTION_ORDER` — عقد مرشح مصحح للمراجعة، لا اعتماد لأولويته أو لدلالة قراءة جديدة، ولا كتابة كود أو نجاح اختبارات غير منفذة. تتضمن موافقة الحزمة قبول حدود قارئ الخيارات الموضحة أدناه؛ إن لم تعتمد فلا يُنشأ القارئ ولا توسّع سلطة observe.

التأهيل يثبت وجود عمل ناقص ومدخلاته وأصحابه ومواضعه وحدوده من المصدر. لا يثبت نشر بيئة أو تراخيص سوق حقيقية، ولا أن هذا هو المسار الوحيد أو أعلى أولوية تجارية للمنصة. أهليته المقترحة ناتجة عن امتداد وظيفة تشغيل بشرية موجودة، لا عن كونه أول أساس واجب. لا يثبت وجود هذه الوظيفة ضرورة تشغيلها الآن أو تفوقها في الأولوية. اختيارها للتنفيذ أولًا يحتاج اختيارًا تشغيليًا صريحًا من المالك؛ لا يكفي استقلالها أو سهولة تحديد ملفاتها.

## 2. اختبار المسار ذي الأولوية والفروع المستقلة

| المرشح | الدليل الحالي | النتيجة المحلية |
|---|---|---|
| D02/D03/D04 أول سلوك Joint Targeting+Spend | التصميم الدلالي المقبول §§17/21/22؛ SharedRealityInterpretation يسمح بوصف الإنفاق المقيد، لا فعالية الاستهداف؛ D3 CustomerDiscoveryConsumer يطلب تأهيل القنوات والجغرافيا؛ QualifiedMarketFact يصرح GEOGRAPHY_IS_NOT_AUDIENCE_REACH | أول شاهد جوهري لم يثبت في هذه المسارات بعد D3؛ لا إعادة بحث تاريخي/حسابات حية. هذا المانع لا يمنع U01-K01 |
| E01/D01 كأساس جديد | processing_authorize وMarketProcessingTask وBusinessScope موجودة؛ الحقول والحقوق والنسخ مؤهلة أصلًا | REUSE؛ لا حاجة مثبتة لخدمة جديدة |
| X01 منهج جديد | D2/D3 لهما أسئلة ثابتة ونتائج محدودة؛ لا منهج إضافي مسمى معتمد هنا | لا يختار منهج Meta أو أسعار جديد لمجرد إظهار كود |
| O01/V01/V02/L01 | معنى الغاية موجود؛ لم يعتمد لهذه المهمة تعريف واقعة بيع/تجربة/ترقية منهج جديدة | لا تفرض تبعية Targeting؛ العقد الجديد نفسه غير محدد، فلا يختلق |
| A01/A02 | السلطات موجودة لكن لا نوع أثر جديد وموارد/تعافٍ معتمدة للحزمة الحالية | لا محرك أو تنفيذ حساب خارجي عام |
| R01/R02 | أكواد المزودين الخمسة والبوابات موجودة؛ G8 لا يشغّل حسابًا أو بيئة إنتاج | تأهيلها الحي مستقل وغير مفوض هنا؛ لا يمنع تشغيل المعرفة المحلية المؤهلة |
| U01-K01 | POST capture/publish قائمان، OperationsView/state يقرآن فقط؛ الاختيارات والسجل التشغيلي الفردي غير متاحين بعقد UI مؤهل | امتداد محدد يمكن وصفه وإثباته دون افتراض نتيجة Targeting |

لا تُعاد توصيف D3 بأنها فاشلة أو ناقصة ضمن نطاقها المقبول. إسقاط المراقبة الحالي مقصود وآمن؛ الوظيفة المقترحة إضافة تشغيلية منفصلة. لا يساوي قبول الاتجاه العام قبول كل تفاصيل هذه الإضافة أو تفويض نشرها.

## 3. الفجوة وملاك العقود

- `app/Http/Controllers/MarketKnowledgeController.php` يقبل أمر capture بحقول command_id/question/material_refs/purpose/authority_selection، وأمر publish بحقوق process/publication مستقلة ومخرج output_scope_id. لا يقبل finding أو ticket من المستدعي.
- `app/MarketKnowledge/KnowledgeCapture.php` يملك معاملة الأمر الموقعة؛ `KnowledgePublisher.php` يلتقط المدخلات ثم يحسب خارج المعاملة ويتحقق ويثبت بإقرار تنفيذ منفصل.
- `resources/js/market-knowledge/OperationsView.vue` و`state.ts` يعرضان GET مراقبة فقط. لا توجد دعوة POST للإعداد/النشر، ولا شاشة اختيار مدخلات مؤهلة.
- `contracts/application.openapi.json` يحتوي capture/publish والمراقبة؛ لا يحتوي `/internal/market-knowledge/workbench`.
- `database/sql/market_knowledge/operations.sql` يحصر observe في metadata. لا يجوز استعماله لإرجاع الأدلة أو تفاصيل التحليل الخاصة أو توسيع حق المراقب.
- `tests/Feature/MarketKnowledgeAuthorityTest.php::test_operational_observation_never_authorizes_shared_capture_or_publication` يثبت العقد المقصود في الاختبار الموجود؛ لم يُعد تشغيله في G8.

**المالكون:** Foundation/Security للهوية والسلطة؛ Source/DataUse للحق لكل مصدر وغرض وحقل؛ MarketKnowledge للالتقاط والمنهج والنشر والصلاحية؛ native SPA للعرض. UI لا يمنح أي حق ولا يقرر ترخيصًا، ولا يبدل نتيجة طريقة. لا تغيير في طرق D2 أو SQL trusted-completion أو دلالة المصادر القائمة.

## 4. العقد المضيق لقارئ الخيارات — سطح إفصاح يحتاج قبولًا صريحًا

`GET /internal/market-knowledge/workbench` وجهة SPA محمية مستقلة، لا توسعة لـGET المراقبة. لا تضيف حقلًا إلى OperationsData ولا تغير observe. المدخلات محصورة في question من الأسئلة الأربعة القائمة، purpose مرخص، cursor محدود، وrun_ref اختياري للتشغيل الحالي المعلوم للمستدعي. ترفض مفاتيح بحث مصادر/مستخدمين/Business أو اقتراح معرفات عامة. cursor ليس سلطة أو رمز تنفيذ.

`MarketKnowledgeWorkbench::read(Request $request, array $query): array` يستعمل المبدأ الموقّع والقارئ SQL الضيق. لا pgsql_migrations ولا raw app table reads. القائمة مساعدة للاختيار، لا شهادة صلاحية قابلة لإعادة الاستخدام. كل POST يعيد فحص الممثل والجيل والجلسة والمورد/النسخة والغرض والحقوق وقت الأثر كما في D3.

### 4.1 مصفوفة الإفصاح — جميع الشروط لازمة للمعلومة نفسها

| الحالة | ما يجوز رده | ما لا يجوز رده |
|---|---|---|
| لا staff حالي/جلسة مؤهلة، أو observe فقط | 403 عام بلا صفوف | كل خيارات المصادر/المواد/الحقوق/التشغيلات |
| capture فقط بلا حقوق بيانات | قدرته الحالية الذاتية على طلب capture وتسميات الأسئلة الثابتة؛ options=[] وnext_cursor=null | وجود مصدر، purpose مشتق منه، material_ref أو عدد أو سبب يشير إلى مورد خفي |
| capture + process بلا display مطابق | نفس حالة غياب خيارات مؤهلة؛ لا عرض مدخلات | المراجع والوجود والنسخة والmetadata والأوصاف، وليس المحتوى فقط |
| display بلا capture/process المطابقين | لا خيارات إعداد معالجة في هذه الوجهة | لا استنتاج process من display؛ لا إظهار مادة لمجرد أنها قابلة للعرض في وجهة أخرى |
| capture + process + display مطابقان | خيارات المادة بعد تحقق الشروط أدناه، للاختيار الواعي فقط | أي حقول/مراجع غير مغطاة أو ترخيص مستقبل أو implied publish |
| publish فقط، دون سياق تشغيل حالي معلوم | قدرة publish الذاتية الحالية وتسميات ثابتة فقط؛ لا مصادر/تشغيلات | لا كتالوج مصادر أو سجل تشغيلات أو اختيارات capture |
| publish + run_ref معلوم | ملخص العملية المطابق فقط وخيارات output_scope المؤهلة بعد تحقق الممثل/الجيل/الجلسة والحقوق الحالية | لا تاريخ عام أو وجود تشغيل لمستخدم آخر؛ لا findings أو أثر على Business |

**قيد حقوق العرض:** discovery نفسه إفصاح. لعرض أي material_ref/وجود/نسخة/ملخص يلزم حق process وحق display مستقلان حاليان للممثل نفسه والrealm المباشر نفسه، لنفس المصدر/المادة/الغرض والتغطية المعنية؛ وتطابق النسخة الحالية من المالك. لا تعتبر الهوية أو «metadata تشغيلية» ترخيصًا لتجاوز ذلك. لا تُصدر هذه الحزمة حقوق عرض لتجعل النموذج يعمل. API capture/publish القائمة لا تُغير معانيها بسبب قيد اكتشاف UI.

**حقول الرد المسموح بها حصرًا:** قدرات المشغّل الذاتية الحالية، تسميات الطرق الثابتة، والغرض المؤهل؛ قائمة material_options تضم material_ref، method-compatible kind، ومعلومات اختيار من display_projection مؤهل. لا source_ref مستقل أو كتالوج صلاحيات كامل. process_selection وdisplay_selection اختيارا جذور للممثل الحالي فقط، داخل نطاق الخيار المعاد، وليسا proof أو lease. purpose/options لا تُستخرج من موارد خفية. كل timestamp أو label أو source name أو schema-derived metadata يمر بمعيار الإفصاح نفسه؛ لا تصدير تلقائي لكل scope/qualification row.

**حقول display_projection الدقيقة:**
- offer: manufacturer/model/variant/unit/condition من product إذا كان حق display يغطي product، وأي شروط خدمة لازمة للتمييز من service عند تغطيتها. لا price/currency أو geography أو مرجع URL في هذه الحزمة لمجرد أن process يستطيع قراءتها.
- interest: subject key وidentity_state فقط إذا شمل display حقل subject، وتعريف المؤشر/الوحدة من metric إذا شمله، وفترة القياس period_start/period_end من comparison_basis إذا شملها؛ لا قيمة المؤشر تلقائيًا.
- geography: level/ref من geography عندما يغطيه display؛ لا تحويله إلى وصول/جمهور.
- الغرض حق استخدام لا وصف قانوني؛ لا licence evidence أو provenance خام أو credentials أو policy internal rows في الرد.
إذا لم يكف العرض المرخص للتمييز والاختيار، لا يملأ من process أو تخمين. يمتنع عن إتاحة الاختيار ويعرض نص نقص معلومات عام، بلا عداد للعناصر المخفية؛ ولا يولد grant جديدًا أو يأمر بمعالجة مدخل لا يفهمه المشغّل.

**إعادة العرض والنشر:** حُذف كتالوج «التشغيلات السابقة» من الحزمة. يسمح فقط بـrun_ref الناتج عن capture في الجيل والجلسة الحاليين، مع إعادة تأهيل ملكيته وحقوق process/publication للممثل الحالي؛ non-existent/foreign/ineligible refs لا تمنح واجهة تمييز وجودها. ملخصه يقتصر على run_ref ومرحلة الإعداد أو receipt النشر المسموح حاليًا. لا finding/سبب تحليلي/تفاصيل سابقة/تاريخ/عدّاد. output_scope_id وpublication_selection لا يظهران قبل ذلك التحقق ولا يتجاوزان هذا التشغيل والغرض. لا نشر إن لم تكن الحقوق القائمة مستقلة ومؤهلة.

**ترقيم وعزل البيانات:** التصفية على أهلية الإفصاح قبل LIMIT أو ترتيب/توليد cursor. لا total_count/hidden_count أو facet counts أو cursor مشتق من مورد غير مؤهل. حد الصفحة50، وnext_cursor مبني على آخر عنصر مؤهل معاد فقط وعند وجود خلف مؤهل. تعاد أهلية كل قراءة؛ تغيير عناصر خفية أو تشغيلات staff آخر أو بيانات Business لا يغير محتوى الخيارات/الرسالة/العدادات/الcursor أو نمط الخطأ. تختبر مساواة هذه المخرجات؛ لا ادعاء constant-time على مستوى قاعدة البيانات من مجرد هذا الاختبار.

**منع التخزين:** Cache-Control:no-store؛ لا HTTP/shared cache أو persistent query cache، ولا محتوى الخيارات أو معايناتها في localStorage/sessionStorage/service worker أو telemetry/log bodies. تمحى الذاكرة عند إغلاق النقل أو تغير الجلسة/الصلاحية. إن احتاج حفظ أمر غير محسوم يستخدم آلية الأوامر المقبولة فقط، بحدود حساسية وعزل الجلسة، لا تخزين ردود القارئ كبديل.

القارئ SQL المقترح `builderad_v2_market_knowledge_workbench_read(session,query jsonb)` يعيد استعمال شروط جذور الحقوق/human staff الحالية؛ يفحص process وdisplay كعمليتين منفصلتين، لا يجمعهما لإضعاف أحدهما، ويحافظ على union resource locking ثم final qualification قبل التسليم. لا computes/captures/command receipts/manifests/invocations/tickets من GET. لا تعديل سلطة staff أو المصدر ولا raw SELECT للتطبيق. أي عجز عن تحقيق هذا العقد بالنموذج القائم يسجل كمانع محدد قبل تنفيذ توسيع حقوق بديل.

### 4.2 شاهد حقوق إيجابي مطلوب — ليس اختبارًا منفذًا

يستعمل test fixture المعزول مشغّل staff حاليًا، ومنحتين process وdisplay مستقلتين لكل واحد من عرضين أصليين محددي النسخة، للغرض نفسه. process يغطي حقول compare_offers المطلوبة؛ display يغطي product وservice الضروريين للتمييز فقط. يسند النموذج المستخدم إلى `MarketKnowledgeFixture::knowledgeRight` و`market_operator_authorize`: grant subject/generation، scope.operation وfield_keys/native_keys، qualification/policy/custody ومواعيدها منفصلة لكل جذر. هذه قراءة مصدر تؤيد إمكان تمثيل الحقوق، وليست دليل نجاح القارئ غير المنفذ.

الاختبار الإيجابي يجب أن يظهر منتجين مختلفين بمعرفات بشرية من product المرخص وشروطهما المسموحة، ويختار المشغّل عرضين واعيًا ثم يعدهما وينشر عبر D3 بحق publication مستقل للمخرج. price أو marker سري في evidence/provenance أو حقل غير مرخص لا يظهر. إزالة display مع إبقاء process تخفي الخيار وكل آثاره، وإزالة process مع إبقاء display تزيل أهلية هذه العملية. بهذا لا تكون كل اختبارات القارئ منعًا فقط ولا تكون الواجهة الآمنة غير قابلة للاستعمال.

## 5. سلوك المستخدم والأثر

- سطح داخلي بعنوان «إعداد المعرفة ونشرها»، ضمن تنقل المعرفة السوقية، وله guard مستقل؛ لا يُفرض market.knowledge.observe على مشغّل يملك عمل capture/publish المشروع وحده.
- يختار العملية والغرض والمدخلات من القوائم المؤهلة. لا UUID أو grant_id أو JSON يكتبه المستخدم؛ المعرفات تبقى قيم نقل داخلية من السيرفر، وليست صلاحية أو لغة المنتج.
- زر «إعداد المعالجة» ينشئ capture_command_id=C ويحفظ جسم capture ثابتًا؛ يرسل C كـcommand_id. رد capture.run_ref=R، والكود الحالي يحفظ R=C. لا يُفترض النجاح قبل receipt مؤهل. تعرض prepared كإعداد فقط.
- زر «معالجة ونشر» ينشئ publish_command_id=P مستقلًا عن C، P!=C، ويحفظ جسم publish ثابتًا يشير إلى run_ref=R. النشر طلب واعٍ منفصل عن الإعداد، لا إجراء إعلاني أو نتيجة تجارية. يعرض receipt published فقط كما يعيده API؛ النشر لا يعني استنتاجًا إيجابيًا، ولا يخترع completed/abstained أو finding لا يعيدها العقد.
- عند ضياع رد capture يعاد C مع جسم capture نفسه لاسترداد R، ولا يستدعى publish حتى استرداد إعداد مؤهل. عند ضياع رد publish يعاد P مع جسم publish نفسه وR نفسه؛ لا إعادة capture ولا إنشاء P2 لإخفاء الغموض. تبقى النتيجة «لم نتأكد» إذا رفضت إعادة التأهيل؛ غياب التأكيد ليس دليل فشل أو نجاح. تجميد الأمر لا يجمد صلاحياته ولا يلغي إعادة الفحص.
- تغيير مدخل/غرض يجعل إعدادًا جديدًا C2 وR2، وأي نشر لاحق له P2 مستقل. لا يبدل جسم C/P القديم ولا يعيد تسمية نتيجته. تغيير output_scope أو اختيارات الحقوق في نشر جديد قرار صريح لا retry للجسم القديم. تغيير جلسة/جيل يغلق العرض ويلغي القراءات؛ R القديم مربوط بالجيل والجلسة وفق publication.sql ولا يُنقل تلقائيًا حتى للمستخدم نفسه. لا handover أو صلاحية استئناف جديدة ضمن الحزمة. refresh فاشل لا يحول snapshot قديمًا إلى إذن عمل جديد.
- حالات عربية/إنجليزية واضحة، RTL، الجوال320/390 والسطح المكتبي، light/dark، لوحة المفاتيح، empty/denied/expired/revoked/stale/conflict/unavailable/unknown؛ لا تعتبر الواجهة مكتملة من API وحده.

## 6. سطح التعديل المقترح

**إضافة ضرورية بسبب قارئ مصرح جديد لقواعد initialized530:**
- Create `database/sql/market_knowledge/workbench.sql`: قارئ محدد وصفوف مؤهلة، fixed search_path وREVOKE PUBLIC وEXECUTE الضيق دون raw privileges للتطبيق.
- Create `database/migrations/0001_01_01_000540_add_qualified_market_knowledge_workbench.php`: ترقية إضافية من530 مع baseline qualification وإعادة ختم الكتالوج، لا تحرير migrations القديمة. يعاد التأكد أن540 غير محجوز قبل التنفيذ.
- Create `app/MarketKnowledge/MarketKnowledgeWorkbench.php` و`app/Http/Controllers/MarketKnowledgeWorkbenchController.php`: طبقة معاملة/استجابة قصيرة مع SpaPage والتحقق المحدود.

**تكامل قائم:**
- Modify `routes/web.php`: GET الجديد ضمن middleware الداخلي الصحيح، دون مساس بـcapture/publish الحاليين.
- Modify `scripts/market-knowledge-openapi.mjs`؛ generate `contracts/application.openapi.json` و`resources/js/api/generated/application.d.ts` عبر المولد الرسمي فقط.
- Create `resources/js/market-knowledge/WorkbenchView.vue` و`workbenchState.ts`؛ modify `resources/js/internal-operations/routes.ts` و`administrationNavigation.ts` لوجهة لها admission/witness وإلغاء وتنظيف كما بقية SPA.
- Add record to `docs/execution/public-platform/SURFACES.md` وملف تقدم الحزمة، مع فصل BACKEND_VERIFIED/UI_INTEGRATED/BROWSER_VERIFIED.

لا تعدل OperationsView/state/OperationsData لزيادة إفصاح observe، ولا KnowledgePublisher/KnowledgeCapture/طرق D2/authority.sql/إقرارات التنفيذ إلا إذا ظهر تعارض مثبت يوقف الجزء المتأثر ويشرح أصغر حل. لا تعدل سجل البرنامج المقبول نفسه لملاحقة حالة التنفيذ؛ سجل الحزمة منفصل ومرتبط به.

## 7. Tasks واختبارات القبول قبل الكود

### Task1 — القارئ المؤهل والترقية

- [ ] أضف `tests/Feature/MarketKnowledgeWorkbenchTest.php` باختبار `test_workbench_lists_only_current_licensed_fields_for_the_signed_operator`؛ قبل التنفيذ route غير موجودة، لا تقبل نجاحًا أو mock بدل السلوك الحقيقي.
- [ ] طبق مصفوفة §4.1 وشاهد §4.2 بإيجابيات وسلبيات، بما فيها process-only/display-only والاختيار المفهوم بلا تسريب. قارِن الرد وcursor ونمط الخطأ قبل/بعد تغيير بيانات غير مؤهلة، واختبر no-store وعدم persistence في المتصفح.
- [ ] اختبارات observe-only وcapture بلا source rights وpublish-only وفصل capture/publication؛ مقطع staff foreign/revoked/expired/source-revoked، وعدم إنشاء بيانات نتيجة أثناء GET؛ لا إنشاء أو تعديل invocation أو ticket أو receipt أو أثر دائم من قراءة الخيارات؛ أقفال التأهيل القصيرة المطلوبة تبقى محفوظة.
- [ ] تحقق مستقل من process-field filtering للملصق والmetadata وprovenance؛ Canary من حقل غير مرخص لا يظهر حتى في message/cursor/count؛ process-only لا يعرض المحتوى، وdisplay-only لا يؤهل للمعالجة، والملخص لا يتجاوز حق display صريحًا. تشغيلات staff آخر وBusiness A/B لا تغير القائمة أو counts.
- [ ] نفذ القارئ/الترقية فقط بعد قبول الحزمة. لا أدوار أو منح تلقائية، لا CREATE متبقٍ لدى التطبيق/المنفذ.
- [ ] أضف `tests/Feature/MarketKnowledgeWorkbenchUpgradeTest.php`: populated530→540 يحفظ evidence/captures/حقوق/receipts وcatalog sealing؛ drift يرفض؛ fresh install؛ partial failure rollback؛ المسار لا يتصل بأدوار نشر.
- [ ] أضف `tests/Feature/MarketKnowledgeWorkbenchConcurrencyTest.php`: holder/wait فعليان، expiry أو سحب حق أثناء الانتظار يمنع التسليم؛ أقفال المراقبة/النشر القديمة محفوظة، لا sleeps بديلة أو timeout أضعف.

### Task2 — وجهة العمل والإعداد والنشر

- [ ] اختبارات محددة: capture-lost-response يعيد C/body_C ويحفظ R واحدًا؛ publish-lost-response يعيد P/body_P لا C ولا P2، وP!=C؛ تغير body تحت C/P يرفض؛ جلسة أخرى لا تستأنف R أو تعيد disclosure؛ replay بعد اكتمال النشر لا ينفذ الطريقة ثانية ويعيد تأهيل الحقوق.
- [ ] أضف اختبارات state ضمن `tests/market-knowledge-operations.test.mjs` أو ملف workbench مسجل في npm test إذا عزلته ضرورة: اختيار مشروط، command frozen/replay، الغرض يتغير بأمر جديد، denial يغلق العرض، refresh فاشل لا يجيز POST، BFCache/جلسة/قراءة سباق لا تستعيد بيانات.
- [ ] نفذ الواجهة بحقول مفهومة وقيم مختارة من القارئ؛ calls capture/publish بأجسام العقود القائمة فقط. لا finding أو ticket أو authority من prompt أو حقل مستخدم حر.
- [ ] عدل `tests/Feature/MarketKnowledgePublicationTest.php` أو أضف integration في WorkbenchTest لإثبات read→capture→compute→validate→trusted publish→qualified replay بالمرجع المختار؛ نفس الأمر لا يكرر التنفيذ، وسحب process/publication ينفصلان.
- [ ] أبق `MarketKnowledgeAuthorityTest` و`MarketKnowledgeCompletionSecurityTest` و`MarketKnowledgeConsumersTest` واختبار العمليات الحالية نجاحًا ضمن نطاقها؛ لا تعديل توقعاتها لتوسيع observe.

### Task3 — الإثبات والقبول

- [ ] تشغيل PHP المتأثر فقط عبر `pwsh -NoProfile -File scripts/builderad.ps1 test --compact --filter 'MarketKnowledgeWorkbench|MarketKnowledgeAuthority|MarketKnowledgeCompletionSecurity|MarketKnowledgePublication|MarketOperations'` مع البيئة المعزولة الرسمية. هذا أمر مخطط لا نتيجة حالية.
- [ ] JavaScript focused للملف المعدل؛ `npm run api:check`، Pint للملفات المتأثرة، `npm run build` مرة عند اكتمال المصدر، وحارس المعمارية عند حدود Core المتأثرة.
- [ ] وسع `tests/Support/market-knowledge-browser.mjs` وfixturesه المؤهلة لإثبات discover→select→prepare→publish→replay وdenial/revoke/unknown، والجوال/المظهر/RTL/keyboard. يستعمل Browser phase في Test-GoalsSpa.ps1؛ لا نختلق الآن خيارات CLI غير مدققة.
- [ ] مراجعة مستقلة للكود الفعلي والاختبارات والقراءة المرخصة والترقية والتزامن والواجهة، مع manifest لنسخة الإثبات.
- [ ] Foundation المستضافة على SHA المنشور بعد تفويض Git فقط؛ لا إعادة شاملة محلية تلقائية ولا تجاوز gate؛ لا نجاح إنتاجي مستنتج منها. إذا استلزمت قراءة الخيارات تغيير دلالة حق process/display أو authority المقبولة بدل إعادة استعمالها، يتوقف هذا الجزء لقرار محدد؛ لا تُضعف الحدود لتشغيل الواجهة.

## 8. Review Focus — الأخطار الخمسة التي يجب أن تكشفها الاختبارات

1. تحويل observe إلى قراءة الأدلة: رفضه في Task1، وإبقاء إسقاط المراقبة القديم مطابقًا.
2. تسريب product/subject/source metadata أو private counts بلا حق field/purpose: Canary وعزل Task1.
3. سحب الحق أو انتهاء الجلسة بين عرض الاختيار والأثر أو أثناء انتظار قفل: Task1/2 final requalification وTask3 browser.
4. رد ضائع بعد أثر ناجح أو تغير مدخلات مع نفس command: frozen-body replay في Task2؛ لا duplicate execution أو تأكيد كاذب.
5. خلط إعداد اللقطة باكتمال التحليل/النشر أو الاستنتاج التجاري: حالات UI والامتناع والتصحيح في Task2/3.

## 9. الاستثناءات وظرف التفويض المقترح

طلب الموافقة المقترح يقتصر على **U01-K01** وعقد قراءة الخيارات أعلاه، والترقية الإضافية المحدودة، وواجهة العمل، واختباراتها. لا يجيز source enrollment/licence administration أو توليد حقوق للمشغّل أو تعديل عروض/ميزانيات مزود أو بناء طرق جديدة أو إلغاء شاهد Targeting أو نشر عام أو scheduler/agent أو فوترة أو تعديل الخطط الخارجية.

**هذه المهمة G8 فقط:** لم ينفذ أي مما سبق، ولم تُشغّل اختبارات أو build أو migration. لا commit/push/merge/deploy. بعد قبول ظرف التنفيذ تنفذ الحزمة inline في الشجرة نفسها، وتعود فقط عند تعارض جوهري مثبت أو نهاية تفويضها. قبولها بعد الإثبات يهيئ التشغيل البشري للأسئلة الأربعة القائمة، ولا يعلن أهلية Targeting أو إطلاق منتج تجاري.


## 10. اختيار الأولوية — لا تفويض مستنتج من الجاهزية

**التصنيف:** U01-K01 حزمة تشغيل داخلية مستقلة تستعمل F01/F03 وD3 المدمجة. ليست عقدًا تأسيسيًا سابقًا لـX01 أو D02/D03/D04 أو O01/V01/V02/L01. أوامر capture/publish موجودة أصلًا؛ غياب UI لا يجعل غياب المعرفة أو عدم إمكان تطوير مستهلك لها. لا تضاف حواف U01-K01 إلى هذه الحزم لتبرير الأولوية.

**أسباب الأهلية فقط:** وظيفة بشرية معروفة في AUTH-D3، فجوة UI ثابتة، إمكان إعادة استعمال العقود والأمن القائمين، وعدم اعتمادها على شاهد Targeting. هذه ليست برهانًا على وجوب تشغيلها الآن أو سبقها لجميع الأساسات.

**مقارنة الفحص الحالي:** F01–F03/E01/D01 إعادة استعمال ولا delta ضروري مثبت؛ المسار المشترك له مانع شاهد محلي؛ X01/O01/A01/V01/L01 تحتاج عقود سؤال/نتيجة/أثر محددة قبل وصف تعديل ضروري؛ R01 يحتاج ادعاء قدرة/بيئة ودليل حي ضمن تفويضه. لم تثبت هنا حزمة تأسيسية منافسة READY بأثر أوسع، لكن عدم ثبوت منافس ليس دليل أولوية U01-K01 ولا إثبات غياب مسؤولية أولى أخرى.

**القرار المعلّق:** لم يصدر اختيار المالك الصريح لـU01-K01 كأول حزمة تنفيذ مستقلة أو أولوية تشغيل حالية. طلب تقييم المراجعات لا يسجل هذا الاختيار. اختيارها إن صدر لا يستبدل أولوية Targeting/Spend الدلالية أو يحل مانعها، ولا يجعل مسار المعرفة UI هو التالي تلقائيًا بعد قبول الحزمة.

**التوازي:** يمكن تأهيل سؤال القرار/الشاهد أو عقد تأسيسي مستقل بالتوازي دون انتظار U01-K01 متى شمله التفويض ولم يتعارض مع ملفات/عقود جارية. هذا وصف استقلال، لا بدء عمل جديد أو تفويض فريق. الانتقال لاحقًا يتبع سجل التبعيات وأولوية المالك وسعة التفويض، لا نوع أول واجهة نفذت.

## 11. حكم استقبال المراجعة — 2026-11-01

التقييم الأشد أثرًا هو الثالث في فصل أولوية الأساس عن جاهزية وظيفة تشغيل؛ والرابع والأول يحددان الإفصاح وهويتي الأمر. ضُبط M1/M2 في العقد والاختبارات المخططة، لا باختبارات منفذة أو إثبات جديد لكفاية SQL. ضُبط M3 بإزالة ادعاء الأولوية وتحديد قرار الاختيار المعلّق؛ لا يستطيع المنفذ إغلاقه نيابة عن المالك.

النتيجة: `AMENDED_CANDIDATE / PRIORITY_SELECTION_AND_IMPLEMENTATION_AUTHORIZATION_PENDING`. لا Stage B ولا SQL/UI code أو migration نفذت. البرنامج v1.2 وقبوله وموانع فروعه محفوظة. لا إعادة Stage A ولا إعادة تصميم D1–D3 ولا ضمان سلامة تشغيلية من فحص وثائقي.


## Owner-directed deferral and B01 result — 2026-11-01

Following the Owner instruction to execute the bounded foundational-priority review, U01-K01 is deferred in the current construction order. Its operational design remains retained; no priority or implementation authorization is inferred. See [B01 precedence result](B01_PRECEDENCE.md). No mandatory upstream edge from U01-K01 to expertise/Targeting/Spend/Outcome is established. Reconsider only on a demonstrated operating dependency or an explicit operational priority decision.
