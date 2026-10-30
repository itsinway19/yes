# Business foundation implementation

Owner authorization: 2026-10-28, explicit reply `موافق` to adoption of G3-F v1.0.1 and execution of E01–E05 in an independent clean checkout and disposable database. Actual customer migration, publication and production deployment are excluded.

Accepted design: BA-FOUNDATION-BUSINESS-SCOPE-G3-FINAL-01 v1.0.1 and its external delivery manifest. Base: bc947c5c60df434cd7bed553fd4ed45c4919fa52, detached, initially clean.

Acceptance begins with independent Business creation, bounded self-declared authority, qualified Workspace linking, and two-business isolation through PostgreSQL and HTTP. Subsequent slices cover facts, independent delegation paths, source/data rights, revocation/concurrency, consumers, isolated upgrades and recovery. Planned cases are not executed evidence.

Build a clean canonical foundation as if new. Reuse conforming components; rebuild components requiring permanent semantic/security exceptions or duplicated logic. Preserve native source/history ownership.

Owner direction, 2026-10-28: Web/Android/iOS share the backend. Resource identity, rights and OpenAPI command/result payloads remain independent of Vue. The present web ingress qualifies browser sessions/documents only; it is not an exclusive authentication architecture for all clients. No mobile authentication implementation or new ingress contract is authorized by this direction.

AI remains a core distributed capability in the accepted product direction. Existing source/data-use/lineage/operation contracts must preserve legitimate future processing, explanation and learning. AI output is not an authority grant or a verified commercial fact. No AI subsystem, speculative adapter or new abstraction is part of this G4 work. These directions continue the current E01–E05 scope.

## شرط القبول المستمر: قدرة يجدها المستخدم ويفهمها ويستفيد منها

توجيه المالك من الملاحظة المرفقة `70e2e853-304b-4a6f-8d28-99c1a65af5e5`، بتاريخ 2026-10-28. هذا شرط داخل E01–E05، وليس تكليف دراسة أو إعادة تصميم شامل أو اعتماد G4 نهائي.

لكل قدرة **موجهة للمستخدم** قبل إعلان اكتمالها:

1. حدد العمل الذي يريد المستخدم إنجازه، والسطح الذي يبدأ منه، والنتيجة العملية التي يراها. لا يبدأ التنظيم بأسماء الجداول أو قائمة تقنية طويلة؛ يبدأ بسياق النشاط والهدف وما يعرفه النظام وما ينقصه وما يمكن فعله الآن.
2. حدد مستخدمها وسياقها: واجهة المعلن، أو إدارة النشاط/الفريق/الوكالة، أو الإدارة الداخلية. هذه أسطح من Backend واحد، لا نظم سلطة مستقلة. الإدارة أو الدفع أو ملكية Workspace لا تمنح قراءة بيانات العميل تلقائيًا.
3. اربط الفعل بعقد API القائم وبإنفاذ حقوق المورد والاشتراك حيث ينطبق، واجعل الظهور انعكاسًا للأهلية الحالية. Vue ليست مالك السلطة، ولا تعاد كتابة الصلاحيات داخل واجهات التطبيقات.
4. استكمل العربية وRTL والتجاوب وقابلية الترجمة، مع لغة تصف العمل الحقيقي. لا تستبدل المصطلحات آليًا؛ إعداد تقني داخلي ليس بالضرورة فعلًا يخص المعلن. التفاصيل المتقدمة تظهر عند الحاجة وبصلاحية مناسبة.
5. غط حالات التحميل والفراغ والخطأ والمصدر الغائب/غير المؤهل، وسحب الوصول والتعارض والنتيجة المجهولة. لا تعرض غياب المصدر كصفر، ولا تعرض الطلب مجهول النتيجة كنجاح، ولا تترك بيانات نشاط سابق بعد التبديل أو السحب.
6. اربط إثبات HTTP/قاعدة البيانات بإثبات المتصفح المنطبق على السطح: العثور على الوظيفة، إتمام الإجراء، ظهور النتيجة، عزل الأنشطة، واختلاف الحقوق. الشاشة أو API وحدها لا تثبت المسار كاملًا.

حالات التقدم منفصلة: `BACKEND_VERIFIED` ثم `UI_INTEGRATED` ثم `BROWSER_VERIFIED`. لا تعني الأولى اكتمال القدرة. لا يوضع `USER_CAPABILITY_COMPLETE` حتى تكتمل الالتزامات المنطبقة مع دليل على النسخة المحددة. حالات المكونات الداخلية لا تستلزم اختراع صفحة إعدادات لكل قفل أو دالة.

بطاقة القبول المختصرة لكل قدرة في سجل التقدم الحالي، لا في سجل موازٍ:

| الحقل | المطلوب |
|---|---|
| العمل والنتيجة | ماذا يريد المستخدم، وما الذي سيتغير له؟ |
| الموضع والجمهور | من أين يصل، ولمن يظهر، وتحت أي نشاط/تفويض؟ |
| العقد والإنفاذ | API/المورد وصلاحياته والاشتراك المنطبق |
| التجربة والحالات | العربية والفعل والنتيجة، والحالات غير الناجحة وحدود المعرفة |
| الدليل | Backend وواجهة ومتصفح على الحالة المختبرة |
| الحكم والمتبقي | مكتمل أو جزئي بأسماء الأسطح غير المنجزة |

**المسار المرجعي الأول:** إنشاء Business مستقل ← العثور عليه في قائمة الأنشطة ← فتحه وتعديل معلوماته ← الانتقال A→B→A دون انتقال بيانات أو مسودات أو أوامر معلقة ← إعادة تأهيل العرض بعد سحب الحق. واجهة Workspace القديمة لا تثبت هذا المسار الجديد.

عند تنفيذ قدرة فعلية يحدد موضع إعداداتها بين: حسابي، النشاط التجاري، الفريق والتعاون، مصادر البيانات، الاشتراك والفوترة، الخصوصية والبيانات، والإدارة الداخلية. لا يلزم بناء هذه الصفحات كلها الآن. أدوات الإدارة تنمو مع الوظيفة الحقيقية وبصلاحياتها؛ لا لوحة شاملة استباقية أو صفحات وهمية.

تظل عقود الموارد مشتركة ومستقلة عن Vue لتخدم التطبيقات المعتمدة لاحقًا. الجلسة الحالية لمدخل الويب، دون إنشاء مصادقة جوال هنا. وتظل AI قدرة أساسية موزعة ضمن حقوق البيانات والأدلة؛ ليست مصدر سلطة أو حقيقة تجارية مثبتة، ولا يبدأ بناؤها ضمن هذا العمل.

## شرط الإغلاق المحدود — توجيه المالك المستمر

وفق توجيه المالك باستكمال التنفيذ وعدم تكرار المشكلات القديمة: يبقى الإنجاز محصورًا في E01–E05 وعقوده المتبقية، ثم اختبارات التكامل والقبول على مرشح واحد. لا تُضاف قدرات تحليلية أو تسويقية أو دراسات معمارية لإطالة بناء الأساس. تُفصل نتائج الشرائح المتداخلة عن بوابة Foundation الشاملة؛ ويُعلن الفشل والخطأ كما هما. نجاح منتج اختباري وسياسة اصطناعية لا يثبت وجود منتج تحليلي تشغيلي أو حقوق استعمال حقيقية. الإغلاق يكون بتنفيذ موثق يحقق العقود أو بمانع محدد بالدليل، مع بقاء ترحيل البيئة الحقيقية منفصلًا. هذا تثبيت للتوجيه القائم، وليس تفويضًا جديدًا أو اعتمادًا نهائيًا لـG4.
