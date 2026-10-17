# مراجعة مستقلة لقبول B/C — Goals SPA

التاريخ: 2026-10-17. Worktree: `C:/dev/builderad-worktrees/ba-spa-openapi-001`.
المرشح المراجع: `ead9bd5e35e937f729906a5d99ab67ffc41e4b9d`، Draft [PR #36](https://github.com/42Huss/builderad/pull/36).
القاعدة: `580837d334ac703209644ffff54bb6d05a62a0c0`. كانت الشجرة نظيفة عند البداية.

**الحكم: A تبقى PASS في نطاقها المحلي السابق؛ B وC تبقيان BLOCKED. لا عيب تطبيقي مؤكد جديد في الحدود المراجعة.**

## معيار القبول قبل الإضافة

- الوثيقة الجديدة لا تعرض بيانات Goals/اسم النشاط قبل JavaScript أو قبل اكتمال تحقق الخادم.
- الهوية القديمة لا تمنح سلطة بعد expiry/revocation/account switch، وWorkspace أجنبي مرفوض مع اعتماد وreceipt صالحين.
- التحميل الجديد عند عدم وقوع BFCache يثبت كمسار مستقل؛ لا يعد نجاحه إثباتًا لاستعادة أصلية.
- الاستعادة الأصلية لا تسجل إلا مع `pageshow.persisted=true` و`isTrusted=true`؛ فحص DOM بعد التنفيذ لا يثبت الإطار الذي يعرضه compositor قبل JavaScript.
- لا تعديل لـno-store أو شروط القبول السابقة. الفصل التحليلي بين المسارين ليس إعفاءً من الشرط الأصلي.

## المراجعة المستقلة وحدود الأمن

قُرئت تقارير التنفيذ والتثبيت والتقرير الأصلي في الشجرة المرجعية، والشيفرة والاختبارات ذات الصلة، وسجل CI الحي. روجعت cookies/renewal/transition/document admission، middleware، WorkspaceContext، controller، النقل المولد، query/drafts، lifecycle وطرق العرض.

- **المصادقة والسلطة:** `AdmitIdentityDocument` يتحقق من receipt مقابل credential/selector/epoch؛ native GET جديد وحده يستطيع bootstrap. تجديد Redis ليس تفويضًا. `EnsureCurrentPrincipal` يحيل أسماء `workspaces.goals.*` إلى المعاملة الحقيقية داخل WorkspaceContext.
- **Workspace/RLS:** السياق يملك المعاملة الخارجية ويعيد فحص lifecycle/session/membership/view grant والمالك، ثم يختار سياق SQL الموقع. controller الجديد يقرأ داخل هذا السياق؛ POST يستعمل الكاتب القائم. لا تغيير في SessionAuthority أو WorkspaceContext أو SQL/migrations أو الكاتب التجاري أو N1 نسبة إلى القاعدة.
- **استمرارية الجلسة:** renewal لا ينشر cookies؛ الإصدار المملوك عند login مقيد بـabsolute deadline، وlegacy لا يحصل على deadline جديد ضمنيًا. مراجعة الشيفرة والأدلة السابقة تدعم A المحدودة؛ لم يُعد تشغيل سباقات A أو توسيعها إلى login/login وسائر السباقات.
- **العرض:** HTML للقشرة يحتوي identity/locale؛ لا projection تجاري. `suspend` يزيد revision ويغلق العرض ويلغي الطلبات؛ النشر يفحص الهوية وتسلسل القراءة والنطاق وwitness. query والمسودات في ذاكرة الوثيقة وتُمسح عند الإغلاق. History لا يحمل business snapshots.
- **حد الحماية:** no-store وpagehide/visibilitychange وwitness مفيدة، لكنها لا تثبت منع لقطة نظام التشغيل أو الإطار المخزن قبل استئناف JavaScript. لا تصنف هذه الفجوة عيبًا مؤكدًا دون إعادة إنتاج، ولا تحولها إلى ضمان.
- **المعمارية:** owner دلالة Goals والكتابة بقي `AdvertiserGoalPreferences`؛ الأنواع والعميل لا يملكان سلطة. INV-004/005/007 تخص فصل الملكية وصدق حالات الدليل وسلطة الأثر؛ لم تتغير دلالة مقبولة تستلزم حماية invariant جديدة.

فُحص تطابق 41 ملفًا من جرد الشيفرة مع HEAD، مع استثناء helper المتصفح المعدل عمدًا. تطابقت بصمات 6 ملفات production SPA مع الجرد النهائي، وتطابقت 17 قطعة من الأدلة السابقة مع بصمات التثبيت. الأدلة السابقة ليست تشغيلًا جديدًا.

دليل RLS السابق يحتوي صف Goals فعليًا وruntime غير مسوق رأى صفر صفوف، مع RLS وFORCE مفعّلين. الجولة الجديدة بلا صفوف Goals، لذلك **صفرها لا يستعمل كبرهان جديد على ترشيح صفوف RLS**. رفض النطاق الأجنبي الجديد اختُبر بـreceipt صحيحة وبلغ403.

## ما أضيف ولماذا

إضافات اختبار فقط: `tests/Support/goals-spa-restoration.mjs`، واستدعاؤه في اختيار restoration من helper القائم. انتظار SPA صار من DOM الإنتاجي، دون `__goalsSpa`. صحح وصف native case ليقول إنه يفحص DOM **بعد** traversal، وسجل `observedPath` و`freshDocumentSafety` مستقليْن عن نتيجة native.

هذا يعالج نقص قياس ووصف دليل، ولا يصلح عيب تطبيق مثبتًا. لم تتغير شيفرة التطبيق أو dependencies أو سياسة cache أو معيار القبول. لا إعادة بناء لمكونات سليمة.

## نتيجة التنفيذ الجديدة

المشغّل الرسمي مع قاعدة معزولة `builderad_test_disposable` وRedis6397، PHP فعلي وCSRF فعلي وChromium `145.0.7632.6` محلي headless. artifact SPA إنتاجي بلا globals proof. ليست هذه بيئة HTTPS منشورة أو جهازًا فعليًا.

| الفحص | النتيجة وحد الدليل |
|---|---|
| قشرة مع JavaScript معطل | PASS:200، `no-store, private`، root فارغ، اسم النشاط غائب، bootstrap مفاتيحه identity/locale فقط |
| reload مع تعليق witness الحقيقي قبل admission | PASS: العرض الخاص مخفي أثناء الانتظار؛ يظهر بعد اكتمال التحقق |
| fresh document مع JS معطل بعد expiry/revocation | PASS:302 إلى login؛200 النهائي هو login وليس قبول Goals |
| fresh document بعد account switch/سحب العضوية | PASS:403 في الحالتين؛ لا نموذج خاص |
| Workspace أجنبي، shell وcontext JSON | PASS:403 لكليهما، مع cookie وreceipt صالحين |
| Back بعد expiry/switch في SPA | سلامة المسار الجديد PASS؛ native BFCache UNVERIFIED في الحالتين |
| native BFCache في Inertia للحالتين | PASS للحدث الأصلي ورفض الخادم/إخفاء DOM بعد الاستعادة؛ لا إثبات compositor |
| بصمات bytes الممررة | PASS:481 رد asset؛46 ملاحظة asset من المتصفح؛ الحد هو bytes PHP الممررة للمتصفح ومطابقتها محليًا |
| syntax للملفين وgit diff --check | PASS |

المجموع: **9 PASS،2 UNVERIFIED،0 FAIL**، و`findings=[]` و`failure=null`.
Node خرج2 عمدًا بسبب BLOCKED، والمشغّل خرج1 بعد رفض البوابة؛ لا يُعرض exit غير الصفري كنجاح قبول أو كعيب تطبيقي.

أسباب عدم استعادة SPA المرصودة: `MainResourceHasCacheControlNoStore` و`JsNetworkRequestReceivedCacheControlNoStoreResource` و`BrowsingInstanceNotSwapped`. لا يلزم أن يقع BFCache في كل تنقل، وهذه الأسباب ليست بحد ذاتها ثغرة. لم تُزل الرؤوس أو تستعمل أحداث persisted مصطنعة.

الأدلة الخام في `storage/framework/testing/goals-spa-5a26eee6-5e44-4d16-8dec-33f38b4651a2/`: `browser-results.json` و`browser.log` و`source-manifest.json` و`source-unchanged.txt=True` و`processes.json`. المحاولة الأولى محفوظة كـattempt قبل تقوية فحص الاسم وreceipt الأجنبي. محاولة sandbox أسبق توقفت عند CIM قبل بدء الخدمات؛ ثم نجح التشغيل المأذون. المتصفح وproxy والخدمات أغلقت؛ تحقق منفصل: لا listeners على منافذ المهمة، وpg_ctl لا يرى خادمًا و.env المؤقت غير موجود.

## Foundation/provenance

[Foundation 39038000416](https://github.com/42Huss/builderad/actions/runs/39038000416) نجحت؛ سجل checkout يثبت:
`7bdd76f10b174b598c6e4f28de99807c9f62e7f5` = merge للـHEAD المطلوب في القاعدة المذكورة.
971 PHP tests/12719 assertions، و285 JavaScript tests ناجحة، مع العقد والتدقيق وPint والبناءين ضمن خطوة Foundation الناجحة.

هذا CI يثبت merge candidate المذكور، وليس future main أو النشر. عدد assertions المحلي السابق13858 مختلف؛ لا نعيد نسبته إلى CI. الـworkflow لا يشغل اختبار المتصفح الجديد؛ هذا الدليل محلي منفصل. لا تغيير تطبيق يستلزم تكرار Foundation محليًا؛ إضافات الاختبار والوثيقة الحالية غير ملتزم بها وغير منشورة ولا ينسب إليها تشغيل CI جديد.

السطر العام القديم في raw browser `unverified` عن Foundation/performance ليس مصفوفة gates محدثة. CI موثق أعلاه؛ الأداء وSSE الطويل ليسا فجوة B الأصلية وفق التقرير الأصلي، ولا نضيفهما شرطًا جديدًا لإغلاق B/C.

## الفحوص المتبقية بالضبط

على artifact إنتاجي مطابق البصمات وcache policy نفسها، مع حسابين اصطناعيين ونطاقين وبيئة اختبار معزولة مناسبة للجهاز:

| الفحص المطلوب | الإجراء والدليل اللازم | الحالة |
|---|---|---|
| native SPA BFCache: expiry/switch | وثيقة A جاهزة ثم مغادرة native؛ expiry أو دخول B من تبويب آخر؛ Back/Forward. سجل trusted pageshow/persisted وطلبات الخادم ورفض القديم. إن عاد مستند جديد فسجل fresh PASS وnative NOT_OBSERVED؛ لا PASS مصطنع | UNVERIFIED |
| جهاز iOS/Safari وجهاز Android/Chrome فعليان | الصفحة جاهزة ثم app switch/قفل الشاشة/خلفية طويلة وتجميد أو قتل العملية؛ أثناء الغياب expiry/revoke/switch/سحب العضوية، ثم العودة وBack/Forward. اختبر أيضًا الجلسة السليمة لإثبات الاستمرارية داخل الآجال، وانتظار التحقق عند بطء/انقطاع الشبكة | NOT_EXECUTED |
| الإطار قبل JavaScript ولقطة app switcher | تصوير خارجي أو وسيلة رصد لا تعتمد على JS الصفحة من لحظة العودة، مع trace الخادم/lifecycle وتحديد ترتيب أول إطار واستئناف التنفيذ. افحص لقطة OS والمعاينة التفاعلية والصفحة بعد الاستعادة منفصلة | NOT_EXECUTED |
| إغلاق C | ربط نتائج الأجهزة بالـSHA/artifacts والبيئة المعلنة؛ استيفاء B، ثم إغلاق C وفق معيارها. إذا تغير تطبيق تعاد الفحوص المتأثرة والبوابات الواجبة على المرشح الجديد | BLOCKED |

السجل لكل تجربة: جهاز/OS/browser version،SHA وبصمات assets ورؤوس cache،هوية ونطاق fixture،نوع العودة trusted persisted أو reload،توقيت الإبطال،سجل الشبكة/الرفض،الرصد المرئي ومدة الخلفية. الهاتف المحاكى أو Playwright WebKit على حاسوب ليسا بديل جهاز iOS. فحص DOM أو screenshot بعد pageshow لا يثبت الإطار السابق لـJS.

لا يلزم تكرار بحث Vue SPA أو بناء طبقة History أو bus أو إعادة تنفيذ الكاتب للحصول على هذه الأدلة.

## هل يمكن إغلاق B/C؟

**لا بالأدلة المتاحة الآن.** يمكن إغلاقهما بإكمال المتطلبات الأصلية بأدلة صحيحة؛ لم تتوفر هذه الأدلة في هذه المهمة.

إذا ظل شرط الاستعادة الأصلية غير قابل للرصد تحت سياسة no-store على المتصفحات المستهدفة، فالتحميل الجديد الآمن لا يسقطه تلقائيًا. يحتاج المالك قرارًا صريحًا يحدد مصفوفة الدعم ومعنى NOT_OBSERVED/N/A المقبول، ويفصل سلامة المسارين عن اشتراط وقوع native BFCache. ويحتاج أي تضييق لمتطلب سرية إطار النظام قبل JS إلى قبول مخاطر صريح وحدود ضمان مكتوبة؛ لا يحرره المراجع بقرار ضمني ولا تمنحه اختبارات Chromium.

التوصية الآن: إبقاء Draft وfeature flag مغلقًا وB/C BLOCKED؛ اختبار الأجهزة وفق الجدول، أو عرض قرار قبول محدود صريح على المالك. لم يُتخذ قرار هنا. لا Merge أو Deployment أو حذف Inertia/N1 أو worktrees أو commit/push.
