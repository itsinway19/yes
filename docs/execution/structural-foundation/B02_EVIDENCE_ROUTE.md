# B02 — First Decision Evidence Acquisition & Contract Feasibility

**تاريخ الفحص:** 1 نوفمبر 2026، Europe/Istanbul. **المهمة:** تأهيل طريق دليل واحد للمقولة المقبولة، لا تنفيذ موصل أو إعادة B01. **الشجرة:** ba-commercial-expertise-001، HEAD `c747f6d8a8f2011fa5dcc368da2aa4e8449e0565`.

**التفويض:** المالك وافق على B02: قراءة المصدر والعقود والتوثيق العام فقط؛ لا حسابات/أسرار/OAuth أو API لحساب إعلاني، لا بيانات خاصة، لا شراء أو تعديل تطبيق أو ترحيل أو نشر. Stage A مقبولة، U01-K01 مؤجلة؛ لا تغيير في PROGRAM.md المقبول.

## 1. الحكم

**`SEMANTIC_GAP — NATIVE_CONFIGURATION_INTERPRETATION_AND_READ_ENTITLEMENT_NOT_QUALIFIED`** للمقولة والفرع المحددين أدناه. يوجد كذلك **قيد إثبات حالة خارجية**: لا locator لحالة أصلية مأذونة مقدمة ضمن المهمة، ولم يُبحث في حسابات أو مخازن خاصة. لا يثبت ذلك عدم وجود حالة اليوم.

النتيجة الجديدة قياسًا بـB01: **انتظار ظهور شاهد داخل المخزن ليس خطة كافية؛ المسارات المفحوصة لا تكتسب/تؤهل هذا النوع من إعداد الاستهداف بالفعل.** توجد فجوة قدرة قراءة typed محددة لهذا الفرع، لكن لم يثبت بعد أنها REQUIRED_BOUNDED_CODE_DELTA جاهزة؛ لأن دلالة القراءة وحد إذنها لم يغلقا، ولم يثبت أن قناة API هي الطريق الوحيد أو المختار. لا إنشاء قارئ أعمى أو importer عام.

هذا ليس فشل D1–D3 أو عيبًا في تقرير Insights أو حظرًا لاختبار عقد اصطناعي ثابت الدلالة. لا اختبار runtime جديد، لا قبول حالة سوقية، لا إقرار كفاية ads_read أو ads_management أو أحدث API version من هذا الفحص.

## 2. المقولة المرجعية والمالك

المقولة المستخدمة من التصميم المقبول، وليست توصية مبتكرة: **شرط استهداف فعلي لكائن معلوم قد يعيق غرضه المقبول داخل سؤال بلد العميل المعلن، إذا ثبتت دلالة الشرط وتطبيقه.** المرجع: Joint Targeting+Spend §17 وTargeting discovery §§3–4 وSR9. ليست سؤال «أفضل جمهور» أو ROI، ولا تفترض وجوب تطابق قائمة بلدان الإعلان مع DTM أو أنها حصر جغرافي عالمي.

N02/Business وCurrent Intent/Object Purpose يملكون التصريحات؛ المصدر يملك إعداد كائنه؛ N05 يؤهل دلالة المشاهدة؛ N06 يملك implication الموجه للجهد؛ N07 يملك حكم المورد المستقل وN08 يركب فقط المساهمات المنطبقة. عقد المورد لا يستنتج من إعداد الاستهداف.

**معيار نجاح B02:** التمييز بين REUSE ودليل مفقود وقناة اكتساب ناقصة ودلالة/إذن غير مؤهلين، مع مسؤول وخطوة إزالة واختبار فارق. لا يتطلب نجاحه إيجاد حالة أصلية أو إصدار استنتاج حين لم يصرح الوصول إليها.

## 3. فحص قنوات التوريد الحالية

| القناة | المصدر المتحقق | ما تتيحه وحدها | الحكم للمقولة |
|---|---|---|---|
| تصريحات النشاط/غرض الكائن | BusinessScope وObject Purpose وعقودهما المقبولة | E1 ومعنى E2 عند وجود نسخة حالة؛ لا تثبت configuration | REUSE كمدخل فقط؛ لا إعداد native من التصريح |
| سجل Provider الحالي | ObservationJournal::snapshot يقبل report.read؛ provider_observation.sql يحصر operation=report.read وشكل campaign/window/spend/native_facts | قراءة وحفظ تقرير مؤهل، لا عقد إعداد AdSet | غير كافٍ؛ native_facts أو مساحة JSON ليست ترخيصًا بتغيير دلالة report |
| Meta Insights | MetaObservationAdapter.fields وMetaAccessAdapter يطلبان حقول تقرير حملة؛ MetaGraphClient نقل محدود بالمسار والسلطة | إنفاق/ظهور/نقرات وصفية ونقل تقارير | لا قارئ typed للاستهداف؛ السماح بـnumeric object path لا يثبت request/fields/parent linkage أو معنى targeting |
| D1–D3 سوقية | market_processing/evidence.sql يقبل offer:1/2 وinterest_signal:1/2 فقط؛ consumers محدودة | عروض/اهتمام/رصد سعر وجغرافيا وتأهيل حقوقها | لا تحوير targeting إلى offer أو interest ولا country إلى effective reach |
| استيراد Business القديم | business_scope/legacy_import.sql ومسارات استيراد مؤهلة | انتقال بيانات قديمة بحده وحارسه | ليس importer أو منصة توريد إعداد استهداف عامة |
| تسليم أصل من جهة مخولة | يمكن أن يكون استجابة API محفوظة/تصديرًا مشروعًا مكتمل الدلالة مع مصدر وزمن وحقوق | قد يوفر مادة للفحص الدلالي دون تشغيل موصل | لا artifact/locator مأذون محدد في B02؛ لم يثبت reader/admission قائم لهذا النوع؛ لا طلب من المالك جمع بيانات عشوائية |

غياب قناة جاهزة في هذه الحدود لا يعني أن جميع إدخال الأدلة مستحيل أو أن API هو الخيار الإلزامي. لا فحص واسع لمخازن أو منصات أخرى ولا تحويل UI إلى شرط اكتساب.

## 4. المصدر الخارجي الموثق — إثبات بنية فقط

استُخدم Meta كمرشح قناة ملاصق للمقولة التي تناولتها السجلات والكود، لا كشرط عام لكل Targeting أو برنامج البناء. لا تركيب SDK في BuilderAd ولا تغيير التقنية.

مصدر رسمي أولي: مستودع Meta العام `meta/facebook-python-business-sdk`، نسخة مثبتة `efd8423a2e595ea8d4c04eb824ce113f2f1d68cd`:

- [AdSet](https://github.com/meta/facebook-python-business-sdk/blob/efd8423a2e595ea8d4c04eb824ce113f2f1d68cd/facebook_business/adobjects/adset.py): عقد object وGET وfield targeting مع علاقة account/campaign. SHA256 `09bb520a7fd99e8e4790ed570f86767c4d1e30070ae7324697c03dba710608ee`، 53589bytes. مراسي: api_get361، GET397، targeting112، account34/campaign54.
- [Targeting](https://github.com/meta/facebook-python-business-sdk/blob/efd8423a2e595ea8d4c04eb824ce113f2f1d68cd/facebook_business/adobjects/targeting.py): أسماء/أنواع geo/exclusion/automation/relaxation. SHA256 `e57afdcf4846a244a369cc3d082a45018906b33e3d73b519b375b7f3443d3a56`، 11027bytes. هذه تعريفات حقول وليست سياسة تفسير أو إثبات entitlement.

صفحات Meta المباشرة المفحوصة: [Ad Set reference](https://developers.facebook.com/docs/marketing-api/reference/ad-campaign/) تعذر جلبها بـtimeout؛ [Permissions](https://developers.facebook.com/docs/permissions/) و[campaign adsets](https://developers.facebook.com/docs/marketing-api/reference/ad-campaign-group/adsets/) أعادتا429. لم تجرَ محاولات متكررة بلا حد، ولا استعملت mirrors أو ملخصات طرف ثالث كمرجع كفاية إذن/دلالة. نتيجة البحث العامة غير موثوقة لتقرير أن ads_read وحدها تكفي لهذا الحقل والasset task والتوكن والنسخة.

المثبت: هناك تمثيل وقراءة object معلنان في مصدر Meta البرمجي. غير المثبت: شروط الإذن الدقيقة لهذا النوع من القراءة، علاقة automation/default/inherited controls بالمعنى الفعال، اكتمال الحقول الضرورية للمقولة في نسخة API محددة، ترخيص الاحتفاظ/الاستخدام لحالة محددة، أو إمكان تنفيذ request على بيئة المستخدم. لا يثبت SDK حالة مُعدّ معلن أو قاعدة «البلد مستبعد إذن الجمهور لن يصل».

## 5. أصغر عقد قناة مرشح — ليس تفويض تنفيذ

المسؤولية المحدودة الممكنة بعد سد المانع: read-only native configuration observation لكائن AdSet محدد المصدر والحساب/الأصل/الزمن، مع الحقول الحاكمة للمقولة فقط وتوثيق coverage. لا رأي Targeting، ولا mutation، ولا تصنيف جمهور/ميزانية/ROI، ولا مفاهيم Meta داخل Core. لا يختار API version أو permission list من الذاكرة.

| موضوع التأهيل | المطلوب قبل وصف delta بأنه واجب | المسؤول/مصدر الحسم |
|---|---|---|
| object applicability | account/object/campaign والنسخة/الزمن/generation والغرض المعلن المنطبق | مالك الهوية/Source + Business/Object Purpose |
| native semantics | inclusion/exclusion والحقول المؤثرة والتوريث/defaults/automation في نسخة معلومة، وكيف نتعامل مع absent vs false | مختص قناة المصدر عند N12 + qualification لدى N05، من توثيق Meta أولي أو أصل مأذون مؤهل |
| access | endpoint/fields وread permission/token type/asset task، وحدود التطبيق والمورد | مسؤول تكامل المصدر/مشغل مخول؛ توثيق رسمي للحق ثم تحقق بيئة عند تفويضه، لا وصول ضمني |
| custody/admission | أصل غير محوّر وprovenance وscope/currentness/rights وكفاية coverage والتصحيح | Source/authority owners؛ تعديل نوع مؤهل فقط إذا ثبت غياب المسار اللازم |
| domain consumption | أي implication يجوز لـN06 وأيها يجب حجبه؛ لا حاجة إلى هوية مستقلة عامة للقرار لإظهار قراءة | N06 ضمن التصميم المقبول؛ الإضافة لا تصدر N07/N08 تلقائيًا |

**قائمة فحص التوريد العملية:** مصدر/نسخة/طلب/غرض؛ حق قارئ محدد؛ عقد حقول وتأويل؛ status/partial/missing؛ timestamps/as-of؛ parent linkage؛ حقوق الاحتفاظ والتصحيح. المواد المشروعة قد تكون test/sandbox موثقًا لبنية العقد؛ نوع الادعاء يحدد هل يلزم شاهد حالة أصلية. بيانات fixture لا تغلق الادعاء الواقعي، لكنها تكفي لإثبات parsing/qualification/رفض النقص بعد تثبيت الدلالة.

## 6. اختبار القبول الفارق إذا أُغلق المانع

لا تكتب الاختبارات الآن لتعكس معنى مجهولًا. عند اعتماد المصدر/الدلالة والعقد:

1. إيجابي: response أصلي الشكل، account/object والنسخة والحقول الحاكمة مع parent وغرض متطابقين، يخرج observation مؤهلًا بحدود معلنة دون ادعاء effectiveness أو تغيير تصريح.
2. سلبي: missing/default غير مفسر أو automation/حقل حاكم ناقص، أو ردpartial، لا يتحول إلى exclusion/zero/false أو conclusion إيجابي.
3. سلطة: read خارج الحساب/الغرض أو حق مسحوب بين صفحتين/بعد انتظار لا يحتفظ/يسلم أثرًا غير مؤهل؛ لا طلب write permission كاختصار.
4. حدود: لا JSON targeting محشور في offer/report على خلاف عقده، ولا claim من التقرير الوصفي وحده؛ تصحيح configuration ينتج نسخة جديدة ولا يغير التاريخ.
5. إدماج: إعادة استعمال guards/transport/lineage المناسبة عند المالك؛ test محلي بمادة اصطناعية وتمييز واضح عن live read. اختبار نقل ناجح ليس قبول case/استنتاج.

الملفات التي **ستحتاج دراسة تعديل عند** ثبوت قناة Meta المختارة: MetaGraphClient/Meta access+read adapters للendpoint qualification، typed observation/parser جديد خاص بالقناة عند الحاجة، عقد حفظ/قراءة شرعي يناسب observation لا يفترض namespace التقرير، واختبارات typed/authority/upgrade بحسب الأثر. لم تحدد أسماء classes أو migration لمجرد إظهار READY؛ لا contract اكتساب مكتمل يسمح بتعيين تلك الإضافات بأمان الآن.

## 7. إجراء إزالة المانع والمسار المستقل

**الإجراء الهندسي التالي المحدد:** إغلاق Native Read Qualification لمقولة configuration هذه من مصدر أولي متاح: نسخة API واحدة، endpoint وfield set واحد، شروط قراءة موثقة، ومعاني التحكم الحاكمة. مسؤول التنفيذ الهندسي يجمع التوثيق العام ويربطه بالعقد؛ لا يطالب المالك باختيار تقنية أو مزود أو بالبحث عن عميل. الاختبار الفاصل هو جدول كل premise→field→native meaning→permission→qualification behavior، مع UNKNOWN صريح عند تعذر شرط.

**حد التعذر الحالي:** الوثائق المباشرة اللازمة لم تُجلب بنجاح، وSDK لا يحسم دلالة/إذن. لا external account access أو بيانات فعلية متاحة بتفويض هذه المهمة. يمكن إزالة قيد المرجع بوثيقة Meta رسمية قابلة للقراءة/نسخة محفوظة بمصدرها أو استجابة أصلية من بيئة مأذونة مع سندها، دون أي سر داخل التقرير. مالك access هو مشغّل المصدر المخول، وقرار فتح الحساب/منح الإذن خاص بالمالك؛ لا يستطيع المنفذ افتراضه أو توليده.

لا يشترط locator حي لكتابة parser إذا اكتمل عقد القراءة الدلالي وثبت احتياج المستهلك؛ لذلك لا نعيد اشتراط متجر حقيقي أو مبيعات. أما الاستنتاج الواقعي فيبقى بحاجة إلى E1–E5 المنطبقة. B02 لا تقدم live-read request plan قابلًا للتشغيل؛ لا تملأ المجهول بإذن أوسع.

**نهاية B02:** لا إعادة B01 ولا جولة بحث شاملة. انتهت عند مانع qualification محدد، بعد إثبات أن مسارات التقرير/السوق ليست قناة REUSE لهذا الفرع، وإثبات بنية native بديلة محتملة فقط. هذه خطوة إلى مصدر المانع، لا إثبات أن حلّه كود الآن. الأولوية التاريخية محفوظة، لكن هذا ليس blocker عامًا لـD03/X01 أو أي فرع مستقل. لا فرع مستقل READY ذو أولوية/عقد جديد ثبت في حدود المهمة؛ U01 لا تعود تلقائيًا لأنها متاحة.

## 8. الأدلة وحالة التنفيذ

توثيق وشيفرة ومصادر عامة فقط. لم نقرأ env أو credential أو DB أو حساب معلن أو storage خاص؛ لا API خاص أو OAuth أو تنزيل بيانات سوق أو شراء. قراءات GitHub الخام كانت نصوص SDK عامة في الذاكرة، لم تثبت مكتبة بالمشروع. لا code/test/SQL/migration تغيّر. لم تشغل PHP/JS/Browser/Foundation. تحقق الروابط المحلية/مراسي المصدر/بصمات النسخة المقبولة/حصر التعديل محفوظ في EVIDENCE.json. المصدر العام لا يقبل تلقائيًا ترخيص بيانات حساب أو إذن إعادة توزيعها.
