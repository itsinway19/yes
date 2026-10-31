export const administrationNavigation = [
    { ar: 'الإدارة', en: 'Administration', links: [
        { to: '/internal/administration', ar: 'الفريق والصلاحيات', en: 'Team and permissions', icon: 'users' },
        { to: '/internal/administration/customers', ar: 'العملاء', en: 'Customers', icon: 'contact' },
    ] },
    { ar: 'الدليل والخدمات', en: 'Catalog and services', links: [
        { to: '/internal/business-types', ar: 'أنواع الأعمال', en: 'Business types', icon: 'catalog' },
        { to: '/internal/services', ar: 'هويات الخدمات', en: 'Service identities', icon: 'service' },
    ] },
    { ar: 'العمليات التجارية', en: 'Commercial operations', links: [
        { to: '/internal/commercial', ar: 'التشغيل التجاري', en: 'Commercial operations', icon: 'briefcase' },
        { to: '/internal/administration/commercial-markets', ar: 'تهيئة الأسواق', en: 'Market preparation', icon: 'globe' },
    ] },
    { ar: 'المنصة', en: 'Platform', links: [
        { to: '/internal/administration/maintenance', ar: 'حالة المنصة والصيانة', en: 'Status and maintenance', icon: 'shield' },
        { to: '/internal/administration/appearance', ar: 'المظهر العام', en: 'Global appearance', icon: 'palette' },
        { to: '/internal/security/devices', ar: 'الحساب والأمان', en: 'Account and security', icon: 'shield' },
    ] },
] as const;
