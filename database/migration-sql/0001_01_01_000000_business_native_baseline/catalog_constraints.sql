-- Name: content_article_revisions id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_article_revisions ALTER COLUMN id SET DEFAULT nextval('public.content_article_revisions_id_seq'::regclass);



-- Name: content_articles id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_articles ALTER COLUMN id SET DEFAULT nextval('public.content_articles_id_seq'::regclass);



-- Name: content_categories id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_categories ALTER COLUMN id SET DEFAULT nextval('public.content_categories_id_seq'::regclass);



-- Name: content_navigation_links id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_links ALTER COLUMN id SET DEFAULT nextval('public.content_navigation_links_id_seq'::regclass);



-- Name: content_navigation_sections id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_sections ALTER COLUMN id SET DEFAULT nextval('public.content_navigation_sections_id_seq'::regclass);



-- Name: content_page_revisions id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_page_revisions ALTER COLUMN id SET DEFAULT nextval('public.content_page_revisions_id_seq'::regclass);



-- Name: content_pages id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_pages ALTER COLUMN id SET DEFAULT nextval('public.content_pages_id_seq'::regclass);



-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);



-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);



-- Name: passkeys id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.passkeys ALTER COLUMN id SET DEFAULT nextval('public.passkeys_id_seq'::regclass);



-- Name: security_action_grants id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants ALTER COLUMN id SET DEFAULT nextval('public.security_action_grants_id_seq'::regclass);



-- Name: security_audit_events id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_audit_events ALTER COLUMN id SET DEFAULT nextval('public.security_audit_events_id_seq'::regclass);



-- Name: security_sessions id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_sessions ALTER COLUMN id SET DEFAULT nextval('public.security_sessions_id_seq'::regclass);



-- Name: service_action_grants id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_action_grants ALTER COLUMN id SET DEFAULT nextval('public.service_action_grants_id_seq'::regclass);



-- Name: service_audit_events id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_audit_events ALTER COLUMN id SET DEFAULT nextval('public.service_audit_events_id_seq'::regclass);



-- Name: service_identities id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_identities ALTER COLUMN id SET DEFAULT nextval('public.service_identities_id_seq'::regclass);



-- Name: users id; Type: DEFAULT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);



-- Name: command_permits command_permits_pkey; Type: CONSTRAINT; Schema: builderad_private; Owner: builderad_owner
--

ALTER TABLE ONLY builderad_private.command_permits
    ADD CONSTRAINT command_permits_pkey PRIMARY KEY (permit_id);



-- Name: invocations invocations_nonce_key; Type: CONSTRAINT; Schema: builderad_private; Owner: builderad_owner
--

ALTER TABLE ONLY builderad_private.invocations
    ADD CONSTRAINT invocations_nonce_key UNIQUE (nonce);



-- Name: invocations invocations_pkey; Type: CONSTRAINT; Schema: builderad_private; Owner: builderad_owner
--

ALTER TABLE ONLY builderad_private.invocations
    ADD CONSTRAINT invocations_pkey PRIMARY KEY (backend_pid, transaction_id);



-- Name: authority_qualification_receipts authority_qualification_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.authority_qualification_receipts
    ADD CONSTRAINT authority_qualification_receipts_pkey PRIMARY KEY (qualification_id);



-- Name: boundary_subjects boundary_subjects_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.boundary_subjects
    ADD CONSTRAINT boundary_subjects_pkey PRIMARY KEY (membership_id);



-- Name: browser_identity_transitions browser_identity_transitions_current_session_key_hash_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.browser_identity_transitions
    ADD CONSTRAINT browser_identity_transitions_current_session_key_hash_key UNIQUE (current_session_key_hash);



-- Name: browser_identity_transitions browser_identity_transitions_last_nonce_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.browser_identity_transitions
    ADD CONSTRAINT browser_identity_transitions_last_nonce_key UNIQUE (last_nonce);



-- Name: browser_identity_transitions browser_identity_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.browser_identity_transitions
    ADD CONSTRAINT browser_identity_transitions_pkey PRIMARY KEY (lineage_id);



-- Name: builderad_context_secret builderad_context_secret_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.builderad_context_secret
    ADD CONSTRAINT builderad_context_secret_pkey PRIMARY KEY (singleton);



-- Name: builderad_context_tickets builderad_context_tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.builderad_context_tickets
    ADD CONSTRAINT builderad_context_tickets_pkey PRIMARY KEY (token_hash);



-- Name: business_authority_fences business_authority_fences_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_authority_fences
    ADD CONSTRAINT business_authority_fences_pkey PRIMARY KEY (business_id);



-- Name: business_branches business_branches_id_business_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_branches
    ADD CONSTRAINT business_branches_id_business_id_key UNIQUE (id, business_id);



-- Name: business_branches business_branches_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_branches
    ADD CONSTRAINT business_branches_pkey PRIMARY KEY (id);



-- Name: business_branches business_branches_public_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_branches
    ADD CONSTRAINT business_branches_public_id_key UNIQUE (public_id);



-- Name: business_command_receipts business_command_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_command_receipts
    ADD CONSTRAINT business_command_receipts_pkey PRIMARY KEY (command_id);



-- Name: business_context_heads business_context_heads_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_context_heads
    ADD CONSTRAINT business_context_heads_pkey PRIMARY KEY (business_id);



-- Name: business_context_transitions business_context_transitions_business_id_after_context_vers_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_context_transitions
    ADD CONSTRAINT business_context_transitions_business_id_after_context_vers_key UNIQUE (business_id, after_context_version);



-- Name: business_context_transitions business_context_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_context_transitions
    ADD CONSTRAINT business_context_transitions_pkey PRIMARY KEY (event_id);



-- Name: business_current_intents business_current_intents_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_current_intents
    ADD CONSTRAINT business_current_intents_pkey PRIMARY KEY (business_id);



-- Name: business_data_use_bindings business_data_use_bindings_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_data_use_bindings
    ADD CONSTRAINT business_data_use_bindings_pkey PRIMARY KEY (binding_id);



-- Name: business_declared_markets business_declared_markets_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_declared_markets
    ADD CONSTRAINT business_declared_markets_pkey PRIMARY KEY (business_id, country_code);



-- Name: business_expenditure_summaries business_expenditure_summaries_output_scope_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_expenditure_summaries
    ADD CONSTRAINT business_expenditure_summaries_output_scope_id_key UNIQUE (output_scope_id);



-- Name: business_expenditure_summaries business_expenditure_summaries_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_expenditure_summaries
    ADD CONSTRAINT business_expenditure_summaries_pkey PRIMARY KEY (summary_id);



-- Name: business_goal_preferences business_goal_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_goal_preferences
    ADD CONSTRAINT business_goal_preferences_pkey PRIMARY KEY (business_id);



-- Name: business_intent_transitions business_intent_transitions_business_id_after_revision_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_intent_transitions
    ADD CONSTRAINT business_intent_transitions_business_id_after_revision_key UNIQUE (business_id, after_revision);



-- Name: business_intent_transitions business_intent_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_intent_transitions
    ADD CONSTRAINT business_intent_transitions_pkey PRIMARY KEY (event_id);



-- Name: business_market_transitions business_market_transitions_business_id_after_context_versi_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_market_transitions
    ADD CONSTRAINT business_market_transitions_business_id_after_context_versi_key UNIQUE (business_id, after_context_version);



-- Name: business_market_transitions business_market_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_market_transitions
    ADD CONSTRAINT business_market_transitions_pkey PRIMARY KEY (event_id);



-- Name: business_practice_assessments business_practice_assessments_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_assessments
    ADD CONSTRAINT business_practice_assessments_pkey PRIMARY KEY (assessment_id);



-- Name: business_practice_revisions business_practice_revisions_command_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_revisions
    ADD CONSTRAINT business_practice_revisions_command_id_key UNIQUE (command_id);



-- Name: business_practice_revisions business_practice_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_revisions
    ADD CONSTRAINT business_practice_revisions_pkey PRIMARY KEY (business_id, practice_id, revision);



-- Name: business_preference_transitions business_preference_transitions_business_id_after_version_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_preference_transitions
    ADD CONSTRAINT business_preference_transitions_business_id_after_version_key UNIQUE (business_id, after_version);



-- Name: business_preference_transitions business_preference_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_preference_transitions
    ADD CONSTRAINT business_preference_transitions_pkey PRIMARY KEY (event_id);



-- Name: business_promotion_priors business_promotion_priors_business_type_id_provider_product_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_promotion_priors
    ADD CONSTRAINT business_promotion_priors_business_type_id_provider_product_key UNIQUE (business_type_id, provider, product);



-- Name: business_promotion_priors business_promotion_priors_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_promotion_priors
    ADD CONSTRAINT business_promotion_priors_pkey PRIMARY KEY (id);



-- Name: business_source_guidance business_source_guidance_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_source_guidance
    ADD CONSTRAINT business_source_guidance_pkey PRIMARY KEY (business_type_id, provider);



-- Name: business_types business_types_key_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_types
    ADD CONSTRAINT business_types_key_key UNIQUE (key);



-- Name: business_types business_types_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_types
    ADD CONSTRAINT business_types_pkey PRIMARY KEY (id);



-- Name: businesses businesses_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.businesses
    ADD CONSTRAINT businesses_pkey PRIMARY KEY (id);



-- Name: businesses businesses_public_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.businesses
    ADD CONSTRAINT businesses_public_id_key UNIQUE (public_id);



-- Name: client_data_boundaries client_data_boundaries_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.client_data_boundaries
    ADD CONSTRAINT client_data_boundaries_pkey PRIMARY KEY (boundary_id);



-- Name: commercial_audit_delivery commercial_audit_delivery_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_audit_delivery
    ADD CONSTRAINT commercial_audit_delivery_pkey PRIMARY KEY (obligation_id);



-- Name: commercial_audit_obligations commercial_audit_obligations_command_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_audit_obligations
    ADD CONSTRAINT commercial_audit_obligations_command_id_key UNIQUE (command_id);



-- Name: commercial_audit_obligations commercial_audit_obligations_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_audit_obligations
    ADD CONSTRAINT commercial_audit_obligations_pkey PRIMARY KEY (id);



-- Name: commercial_beneficiary_bindings commercial_beneficiary_bindings_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_beneficiary_bindings
    ADD CONSTRAINT commercial_beneficiary_bindings_pkey PRIMARY KEY (id);



-- Name: commercial_beneficiary_bindings commercial_beneficiary_bindings_scope_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_beneficiary_bindings
    ADD CONSTRAINT commercial_beneficiary_bindings_scope_id_key UNIQUE (scope_id);



-- Name: commercial_beneficiary_bindings commercial_beneficiary_bindings_scope_id_business_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_beneficiary_bindings
    ADD CONSTRAINT commercial_beneficiary_bindings_scope_id_business_id_key UNIQUE (scope_id, business_id);



-- Name: commercial_beneficiary_bindings commercial_beneficiary_bindings_business_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_beneficiary_bindings
    ADD CONSTRAINT commercial_beneficiary_bindings_business_id_key UNIQUE (business_id);



-- Name: CONSTRAINT commercial_beneficiary_bindings_business_id_key ON commercial_beneficiary_bindings; Type: COMMENT; Schema: public; Owner: builderad_owner
--

COMMENT ON CONSTRAINT commercial_beneficiary_bindings_business_id_key ON public.commercial_beneficiary_bindings IS 'SA-01 initial registration only: reject competing initial registrations; no universal future binding-cardinality policy';



-- Name: commercial_catalog_references commercial_catalog_references_catalog_identity_catalog_vers_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_catalog_references
    ADD CONSTRAINT commercial_catalog_references_catalog_identity_catalog_vers_key UNIQUE (catalog_identity, catalog_version);



-- Name: commercial_catalog_references commercial_catalog_references_id_market_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_catalog_references
    ADD CONSTRAINT commercial_catalog_references_id_market_id_key UNIQUE (id, market_id);



-- Name: commercial_catalog_references commercial_catalog_references_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_catalog_references
    ADD CONSTRAINT commercial_catalog_references_pkey PRIMARY KEY (id);



-- Name: commercial_command_results commercial_command_results_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_command_results
    ADD CONSTRAINT commercial_command_results_pkey PRIMARY KEY (command_id);



-- Name: commercial_founder_market_notes commercial_founder_market_notes_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_founder_market_notes
    ADD CONSTRAINT commercial_founder_market_notes_pkey PRIMARY KEY (command_id);



-- Name: commercial_market_assignability commercial_market_assignability_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_assignability
    ADD CONSTRAINT commercial_market_assignability_pkey PRIMARY KEY (market_id);



-- Name: commercial_market_assignments commercial_market_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_assignments
    ADD CONSTRAINT commercial_market_assignments_pkey PRIMARY KEY (id);



-- Name: commercial_market_assignments commercial_market_assignments_scope_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_assignments
    ADD CONSTRAINT commercial_market_assignments_scope_id_key UNIQUE (scope_id);



-- Name: commercial_market_configuration_versions commercial_market_configurati_id_market_id_catalog_referenc_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_configuration_versions
    ADD CONSTRAINT commercial_market_configurati_id_market_id_catalog_referenc_key UNIQUE (id, market_id, catalog_reference_id, denomination_code);



-- Name: commercial_market_configuration_versions commercial_market_configuration_versions_id_market_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_configuration_versions
    ADD CONSTRAINT commercial_market_configuration_versions_id_market_id_key UNIQUE (id, market_id);



-- Name: commercial_market_configuration_versions commercial_market_configuration_versions_market_id_version_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_configuration_versions
    ADD CONSTRAINT commercial_market_configuration_versions_market_id_version_key UNIQUE (market_id, version);



-- Name: commercial_market_configuration_versions commercial_market_configuration_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_configuration_versions
    ADD CONSTRAINT commercial_market_configuration_versions_pkey PRIMARY KEY (id);



-- Name: commercial_market_countries commercial_market_countries_command_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_countries
    ADD CONSTRAINT commercial_market_countries_command_id_key UNIQUE (command_id);



-- Name: commercial_market_countries commercial_market_countries_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_countries
    ADD CONSTRAINT commercial_market_countries_pkey PRIMARY KEY (market_id);



-- Name: commercial_markets commercial_markets_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_markets
    ADD CONSTRAINT commercial_markets_pkey PRIMARY KEY (id);



-- Name: commercial_scopes commercial_scopes_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_scopes
    ADD CONSTRAINT commercial_scopes_pkey PRIMARY KEY (id);



-- Name: content_article_revisions content_article_revisions_article_id_revision_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_article_revisions
    ADD CONSTRAINT content_article_revisions_article_id_revision_key UNIQUE (article_id, revision);



-- Name: content_article_revisions content_article_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_article_revisions
    ADD CONSTRAINT content_article_revisions_pkey PRIMARY KEY (id);



-- Name: content_articles content_articles_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_articles
    ADD CONSTRAINT content_articles_pkey PRIMARY KEY (id);



-- Name: content_articles content_articles_public_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_articles
    ADD CONSTRAINT content_articles_public_id_key UNIQUE (public_id);



-- Name: content_categories content_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_categories
    ADD CONSTRAINT content_categories_pkey PRIMARY KEY (id);



-- Name: content_categories content_categories_public_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_categories
    ADD CONSTRAINT content_categories_public_id_key UNIQUE (public_id);



-- Name: content_footer_settings content_footer_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_footer_settings
    ADD CONSTRAINT content_footer_settings_pkey PRIMARY KEY (id);



-- Name: content_navigation_links content_navigation_links_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_links
    ADD CONSTRAINT content_navigation_links_pkey PRIMARY KEY (id);



-- Name: content_navigation_links content_navigation_links_public_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_links
    ADD CONSTRAINT content_navigation_links_public_id_key UNIQUE (public_id);



-- Name: content_navigation_sections content_navigation_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_sections
    ADD CONSTRAINT content_navigation_sections_pkey PRIMARY KEY (id);



-- Name: content_navigation_sections content_navigation_sections_public_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_sections
    ADD CONSTRAINT content_navigation_sections_public_id_key UNIQUE (public_id);



-- Name: content_page_revisions content_page_revisions_page_id_revision_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_page_revisions
    ADD CONSTRAINT content_page_revisions_page_id_revision_key UNIQUE (page_id, revision);



-- Name: content_page_revisions content_page_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_page_revisions
    ADD CONSTRAINT content_page_revisions_pkey PRIMARY KEY (id);



-- Name: content_pages content_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_pages
    ADD CONSTRAINT content_pages_pkey PRIMARY KEY (id);



-- Name: content_pages content_pages_public_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_pages
    ADD CONSTRAINT content_pages_public_id_key UNIQUE (public_id);



-- Name: country_references country_references_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.country_references
    ADD CONSTRAINT country_references_pkey PRIMARY KEY (code);



-- Name: current_intent_timezone_catalog current_intent_timezone_catalog_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.current_intent_timezone_catalog
    ADD CONSTRAINT current_intent_timezone_catalog_pkey PRIMARY KEY (id);



-- Name: customer_administration_events customer_administration_events_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.customer_administration_events
    ADD CONSTRAINT customer_administration_events_pkey PRIMARY KEY (command_id);



-- Name: data_use_scopes data_use_scopes_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.data_use_scopes
    ADD CONSTRAINT data_use_scopes_pkey PRIMARY KEY (scope_id);



-- Name: data_use_scopes data_use_scopes_scope_digest_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.data_use_scopes
    ADD CONSTRAINT data_use_scopes_scope_digest_key UNIQUE (scope_digest);



-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);



-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);



-- Name: global_appearance global_appearance_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.global_appearance
    ADD CONSTRAINT global_appearance_pkey PRIMARY KEY (id);



-- Name: google_read_attempts google_read_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.google_read_attempts
    ADD CONSTRAINT google_read_attempts_pkey PRIMARY KEY (namespace, command_id);



-- Name: inquiry_evidence_enrollments inquiry_evidence_enrollments_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_enrollments
    ADD CONSTRAINT inquiry_evidence_enrollments_pkey PRIMARY KEY (qualification_id);



-- Name: inquiry_evidence_heads inquiry_evidence_heads_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_heads
    ADD CONSTRAINT inquiry_evidence_heads_pkey PRIMARY KEY (declaration_id);



-- Name: inquiry_evidence_revisions inquiry_evidence_revisions_command_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_revisions
    ADD CONSTRAINT inquiry_evidence_revisions_command_id_key UNIQUE (command_id);



-- Name: inquiry_evidence_revisions inquiry_evidence_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_revisions
    ADD CONSTRAINT inquiry_evidence_revisions_pkey PRIMARY KEY (declaration_id, revision);



-- Name: internal_personnel internal_personnel_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.internal_personnel
    ADD CONSTRAINT internal_personnel_pkey PRIMARY KEY (user_id);



-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);



-- Name: market_business_finding_captures market_business_finding_captures_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_business_finding_captures
    ADD CONSTRAINT market_business_finding_captures_pkey PRIMARY KEY (artifact_id);



-- Name: market_evidence_heads market_evidence_heads_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_heads
    ADD CONSTRAINT market_evidence_heads_pkey PRIMARY KEY (evidence_id);



-- Name: market_evidence_heads market_evidence_heads_source_id_record_key_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_heads
    ADD CONSTRAINT market_evidence_heads_source_id_record_key_key UNIQUE (source_id, record_key);



-- Name: market_evidence_revisions market_evidence_revisions_evidence_id_content_digest_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_revisions
    ADD CONSTRAINT market_evidence_revisions_evidence_id_content_digest_key UNIQUE (evidence_id, content_digest);



-- Name: market_evidence_revisions market_evidence_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_revisions
    ADD CONSTRAINT market_evidence_revisions_pkey PRIMARY KEY (evidence_id, revision);



-- Name: market_evidence_transitions market_evidence_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_transitions
    ADD CONSTRAINT market_evidence_transitions_pkey PRIMARY KEY (command_id);



-- Name: market_knowledge_captures market_knowledge_captures_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_knowledge_captures
    ADD CONSTRAINT market_knowledge_captures_pkey PRIMARY KEY (run_id);



-- Name: market_knowledge_completion_tickets market_knowledge_completion_tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_knowledge_completion_tickets
    ADD CONSTRAINT market_knowledge_completion_tickets_pkey PRIMARY KEY (token_hash);



-- Name: market_processing_completion_tickets market_processing_completion_tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_processing_completion_tickets
    ADD CONSTRAINT market_processing_completion_tickets_pkey PRIMARY KEY (token_hash);



-- Name: market_processing_runs market_processing_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_processing_runs
    ADD CONSTRAINT market_processing_runs_pkey PRIMARY KEY (run_id);



-- Name: market_source_custody market_source_custody_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_source_custody
    ADD CONSTRAINT market_source_custody_pkey PRIMARY KEY (source_id);



-- Name: material_lineage_manifests material_lineage_manifests_material_namespace_material_key__key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.material_lineage_manifests
    ADD CONSTRAINT material_lineage_manifests_material_namespace_material_key__key UNIQUE (material_namespace, material_key, material_version);



-- Name: material_lineage_manifests material_lineage_manifests_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.material_lineage_manifests
    ADD CONSTRAINT material_lineage_manifests_pkey PRIMARY KEY (manifest_id);



-- Name: material_use_dependencies material_use_dependencies_material_namespace_material_key_m_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.material_use_dependencies
    ADD CONSTRAINT material_use_dependencies_material_namespace_material_key_m_key UNIQUE (material_namespace, material_key, material_version, input_namespace, input_key, input_version, data_use_scope_id, policy_id, policy_version);



-- Name: material_use_dependencies material_use_dependencies_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.material_use_dependencies
    ADD CONSTRAINT material_use_dependencies_pkey PRIMARY KEY (dependency_id);



-- Name: meta_read_attempts meta_read_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.meta_read_attempts
    ADD CONSTRAINT meta_read_attempts_pkey PRIMARY KEY (namespace, command_id);



-- Name: migration_cohort_control migration_cohort_control_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.migration_cohort_control
    ADD CONSTRAINT migration_cohort_control_pkey PRIMARY KEY (cohort_id);



-- Name: migration_cohort_members migration_cohort_members_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.migration_cohort_members
    ADD CONSTRAINT migration_cohort_members_pkey PRIMARY KEY (member_id);



-- Name: object_purpose_business_context_refs object_purpose_business_conte_native_revision_id_business_i_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_business_context_refs
    ADD CONSTRAINT object_purpose_business_conte_native_revision_id_business_i_key UNIQUE (native_revision_id, business_id);



-- Name: object_purpose_business_context_refs object_purpose_business_context_refs_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_business_context_refs
    ADD CONSTRAINT object_purpose_business_context_refs_pkey PRIMARY KEY (ref_id);



-- Name: object_purpose_command_receipts object_purpose_command_receipts_business_id_command_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_command_receipts
    ADD CONSTRAINT object_purpose_command_receipts_business_id_command_id_key UNIQUE (business_id, command_id);



-- Name: object_purpose_command_receipts object_purpose_command_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_command_receipts
    ADD CONSTRAINT object_purpose_command_receipts_pkey PRIMARY KEY (purpose_id, command_id);



-- Name: object_purpose_revisions object_purpose_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_revisions
    ADD CONSTRAINT object_purpose_revisions_pkey PRIMARY KEY (purpose_id, version);



-- Name: object_purpose_revisions object_purpose_revisions_purpose_id_revision_id_business_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_revisions
    ADD CONSTRAINT object_purpose_revisions_purpose_id_revision_id_business_id_key UNIQUE (purpose_id, revision_id, business_id);



-- Name: object_purpose_revisions object_purpose_revisions_revision_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_revisions
    ADD CONSTRAINT object_purpose_revisions_revision_id_key UNIQUE (revision_id);



-- Name: object_purposes object_purposes_id_business_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purposes
    ADD CONSTRAINT object_purposes_id_business_id_key UNIQUE (id, business_id);



-- Name: object_purposes object_purposes_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purposes
    ADD CONSTRAINT object_purposes_pkey PRIMARY KEY (id);



-- Name: passkeys passkeys_credential_id_unique; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.passkeys
    ADD CONSTRAINT passkeys_credential_id_unique UNIQUE (credential_id);



-- Name: passkeys passkeys_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.passkeys
    ADD CONSTRAINT passkeys_pkey PRIMARY KEY (id);



-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);



-- Name: platform_maintenance_commands platform_maintenance_commands_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.platform_maintenance_commands
    ADD CONSTRAINT platform_maintenance_commands_pkey PRIMARY KEY (command_id);



-- Name: platform_maintenance_commands platform_maintenance_commands_resulting_revision_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.platform_maintenance_commands
    ADD CONSTRAINT platform_maintenance_commands_resulting_revision_key UNIQUE (resulting_revision);



-- Name: platform_maintenance platform_maintenance_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.platform_maintenance
    ADD CONSTRAINT platform_maintenance_pkey PRIMARY KEY (id);



-- Name: principal_lifecycles principal_lifecycles_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.principal_lifecycles
    ADD CONSTRAINT principal_lifecycles_pkey PRIMARY KEY (user_id);



-- Name: provider_access_events provider_access_events_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_access_events
    ADD CONSTRAINT provider_access_events_pkey PRIMARY KEY (namespace, command_id);



-- Name: provider_access provider_access_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_access
    ADD CONSTRAINT provider_access_pkey PRIMARY KEY (source_id, provider, resource, operation, purpose);



-- Name: provider_access_receipts provider_access_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_access_receipts
    ADD CONSTRAINT provider_access_receipts_pkey PRIMARY KEY (namespace, command_id);



-- Name: provider_observation_entries provider_observation_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_entries
    ADD CONSTRAINT provider_observation_entries_pkey PRIMARY KEY (namespace, generation, identity);



-- Name: provider_observation_runs provider_observation_runs_namespace_command_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_runs
    ADD CONSTRAINT provider_observation_runs_namespace_command_id_key UNIQUE (namespace, command_id);



-- Name: provider_observation_runs provider_observation_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_runs
    ADD CONSTRAINT provider_observation_runs_pkey PRIMARY KEY (namespace, generation);



-- Name: provider_observation_streams provider_observation_streams_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_streams
    ADD CONSTRAINT provider_observation_streams_pkey PRIMARY KEY (namespace);



-- Name: provider_observation_streams provider_observation_streams_source_id_provider_resource_op_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_streams
    ADD CONSTRAINT provider_observation_streams_source_id_provider_resource_op_key UNIQUE (source_id, provider, resource, operation, purpose, stream);



-- Name: registration_email_proofs registration_email_proofs_lineage_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.registration_email_proofs
    ADD CONSTRAINT registration_email_proofs_lineage_id_key UNIQUE (lineage_id);



-- Name: registration_email_proofs registration_email_proofs_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.registration_email_proofs
    ADD CONSTRAINT registration_email_proofs_pkey PRIMARY KEY (attempt_id);



-- Name: registration_email_proofs registration_email_proofs_user_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.registration_email_proofs
    ADD CONSTRAINT registration_email_proofs_user_id_key UNIQUE (user_id);



-- Name: resource_right_fences resource_right_fences_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.resource_right_fences
    ADD CONSTRAINT resource_right_fences_pkey PRIMARY KEY (fence_id);



-- Name: security_action_grants security_action_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants
    ADD CONSTRAINT security_action_grants_pkey PRIMARY KEY (id);



-- Name: security_audit_events security_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_audit_events
    ADD CONSTRAINT security_audit_events_pkey PRIMARY KEY (id);



-- Name: security_commercial_audit_custody security_commercial_audit_custody_acknowledgment_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_audit_custody
    ADD CONSTRAINT security_commercial_audit_custody_acknowledgment_id_key UNIQUE (acknowledgment_id);



-- Name: security_commercial_audit_custody security_commercial_audit_custody_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_audit_custody
    ADD CONSTRAINT security_commercial_audit_custody_pkey PRIMARY KEY (obligation_id);



-- Name: security_commercial_mandate_executions security_commercial_mandate_executions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_mandate_executions
    ADD CONSTRAINT security_commercial_mandate_executions_pkey PRIMARY KEY (mandate_id);



-- Name: security_commercial_market_approvals security_commercial_market_approvals_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_market_approvals
    ADD CONSTRAINT security_commercial_market_approvals_pkey PRIMARY KEY (id);



-- Name: security_commercial_market_approvals security_commercial_market_approvals_target_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_market_approvals
    ADD CONSTRAINT security_commercial_market_approvals_target_id_key UNIQUE (target_id);



-- Name: security_sessions security_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_sessions
    ADD CONSTRAINT security_sessions_pkey PRIMARY KEY (id);



-- Name: security_sessions security_sessions_session_key_hash_unique; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_sessions
    ADD CONSTRAINT security_sessions_session_key_hash_unique UNIQUE (session_key_hash);



-- Name: security_source_audit_custody security_source_audit_custody_acknowledgment_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_source_audit_custody
    ADD CONSTRAINT security_source_audit_custody_acknowledgment_id_key UNIQUE (acknowledgment_id);



-- Name: security_source_audit_custody security_source_audit_custody_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_source_audit_custody
    ADD CONSTRAINT security_source_audit_custody_pkey PRIMARY KEY (obligation_id);



-- Name: service_action_grants service_action_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_action_grants
    ADD CONSTRAINT service_action_grants_pkey PRIMARY KEY (id);



-- Name: service_audit_events service_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_audit_events
    ADD CONSTRAINT service_audit_events_pkey PRIMARY KEY (id);



-- Name: service_identities service_identities_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_identities
    ADD CONSTRAINT service_identities_pkey PRIMARY KEY (id);



-- Name: service_identities service_identities_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_identities
    ADD CONSTRAINT service_identities_public_id_unique UNIQUE (public_id);



-- Name: service_business_scopes service_business_scopes_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_business_scopes
    ADD CONSTRAINT service_business_scopes_pkey PRIMARY KEY (service_identity_id, business_id);



-- Name: snapchat_read_attempts snapchat_read_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.snapchat_read_attempts
    ADD CONSTRAINT snapchat_read_attempts_pkey PRIMARY KEY (namespace, command_id);



-- Name: snapchat_renewal_failures snapchat_renewal_failures_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.snapchat_renewal_failures
    ADD CONSTRAINT snapchat_renewal_failures_pkey PRIMARY KEY (source_id, resource, operation, purpose, command_id);



-- Name: source_audit_delivery source_audit_delivery_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_audit_delivery
    ADD CONSTRAINT source_audit_delivery_pkey PRIMARY KEY (obligation_id);



-- Name: source_audit_obligations source_audit_obligations_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_audit_obligations
    ADD CONSTRAINT source_audit_obligations_pkey PRIMARY KEY (id);



-- Name: source_audit_obligations source_audit_obligations_source_id_command_id_key; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_audit_obligations
    ADD CONSTRAINT source_audit_obligations_source_id_command_id_key UNIQUE (source_id, command_id);



-- Name: source_capabilities source_capabilities_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_capabilities
    ADD CONSTRAINT source_capabilities_pkey PRIMARY KEY (source_id, resource, operation, purpose);



-- Name: source_command_receipts source_command_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_command_receipts
    ADD CONSTRAINT source_command_receipts_pkey PRIMARY KEY (source_id, command_id);



-- Name: source_registry source_registry_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_registry
    ADD CONSTRAINT source_registry_pkey PRIMARY KEY (id);



-- Name: source_versions source_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_versions
    ADD CONSTRAINT source_versions_pkey PRIMARY KEY (source_id, version);



-- Name: tiktok_read_attempts tiktok_read_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.tiktok_read_attempts
    ADD CONSTRAINT tiktok_read_attempts_pkey PRIMARY KEY (namespace, command_id);



-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);



-- Name: users users_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_public_id_unique UNIQUE (public_id);



-- Name: x_read_attempts x_read_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.x_read_attempts
    ADD CONSTRAINT x_read_attempts_pkey PRIMARY KEY (namespace, command_id);



-- Name: active_boundary_branch; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX active_boundary_branch ON public.boundary_subjects USING btree (boundary_id, business_id, branch_id) WHERE ((status = 'active'::text) AND (branch_id IS NOT NULL));



-- Name: active_boundary_business; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX active_boundary_business ON public.boundary_subjects USING btree (boundary_id, business_id) WHERE ((status = 'active'::text) AND (branch_id IS NULL));



-- Name: active_business_data_binding; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX active_business_data_binding ON public.business_data_use_bindings USING btree (business_id, data_use_scope_id) WHERE (status = 'active'::text);



-- Name: agency_qualification_basis; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX agency_qualification_basis ON public.authority_qualification_receipts USING btree (agency_business_id, agency_membership_grant_id) WHERE (agency_business_id IS NOT NULL);



-- Name: boundary_grant_lookup; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX boundary_grant_lookup ON public.security_action_grants USING btree (boundary_id, subject_user_id, subject_generation, action, status) WHERE ((scope_kind)::text = 'client_data'::text);



-- Name: boundary_right_gate; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX boundary_right_gate ON public.resource_right_fences USING btree (boundary_id) WHERE (kind = 'client_boundary'::text);



-- Name: builderad_context_tickets_expires_at_idx; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX builderad_context_tickets_expires_at_idx ON public.builderad_context_tickets USING btree (expires_at);



-- Name: business_grant_lookup; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX business_grant_lookup ON public.security_action_grants USING btree (business_id, subject_user_id, subject_generation, action, status) WHERE ((scope_kind)::text = 'business'::text);



-- Name: business_native_source_identity; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX business_native_source_identity ON public.source_registry USING btree (business_id, family, namespace, declared_external_id) WHERE (custody_kind = 'business_native'::text);



-- Name: business_practice_identity; Type: INDEX; Schema: public; Owner: builderad_scope_executor
--

CREATE UNIQUE INDEX business_practice_identity ON public.business_practice_revisions USING btree (practice_id) WHERE (revision = 1);



-- Name: cohort_business_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX cohort_business_unique ON public.migration_cohort_members USING btree (business_id) WHERE (kind = 'business'::text);



-- Name: content_articles_public_order; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX content_articles_public_order ON public.content_articles USING btree ("position", published_at DESC NULLS LAST, id DESC) WHERE (deleted_at IS NULL);



-- Name: content_articles_slug_ar_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX content_articles_slug_ar_unique ON public.content_articles USING btree (lower(slug_ar)) WHERE (deleted_at IS NULL);



-- Name: content_articles_slug_en_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX content_articles_slug_en_unique ON public.content_articles USING btree (lower(slug_en)) WHERE (deleted_at IS NULL);



-- Name: content_categories_slug_ar_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX content_categories_slug_ar_unique ON public.content_categories USING btree (lower(slug_ar)) WHERE (deleted_at IS NULL);



-- Name: content_categories_slug_en_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX content_categories_slug_en_unique ON public.content_categories USING btree (lower(slug_en)) WHERE (deleted_at IS NULL);



-- Name: content_navigation_links_order; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX content_navigation_links_order ON public.content_navigation_links USING btree (section_id, "position", id) WHERE (deleted_at IS NULL);



-- Name: content_navigation_sections_order; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX content_navigation_sections_order ON public.content_navigation_sections USING btree (placement, "position", id) WHERE (deleted_at IS NULL);



-- Name: content_pages_public_order; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX content_pages_public_order ON public.content_pages USING btree ("position", id) WHERE (deleted_at IS NULL);



-- Name: content_pages_slug_ar_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX content_pages_slug_ar_unique ON public.content_pages USING btree (lower(slug_ar)) WHERE (deleted_at IS NULL);



-- Name: content_pages_slug_en_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX content_pages_slug_en_unique ON public.content_pages USING btree (lower(slug_en)) WHERE (deleted_at IS NULL);



-- Name: customer_administration_user_history; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX customer_administration_user_history ON public.customer_administration_events USING btree (subject_user_id, occurred_at DESC);



-- Name: data_use_grant_lookup; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX data_use_grant_lookup ON public.security_action_grants USING btree (data_use_scope_id, subject_user_id, subject_generation, action, status) WHERE ((scope_kind)::text = 'data_use'::text);



-- Name: grant_parent_status; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX grant_parent_status ON public.security_action_grants USING btree (parent_grant_id, status);



-- Name: inquiry_enrollment_actor; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX inquiry_enrollment_actor ON public.inquiry_evidence_enrollments USING btree (business_id, actor_user_id, generation, created_at);



-- Name: inquiry_evidence_scope; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX inquiry_evidence_scope ON public.inquiry_evidence_heads USING btree (business_id, issuer_user_id, declaration_id);



-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);



-- Name: knowledge_knowledge_ticket_expiry; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX knowledge_knowledge_ticket_expiry ON public.market_knowledge_completion_tickets USING btree (expires_at);



-- Name: market_knowledge_operations_order; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX market_knowledge_operations_order ON public.market_knowledge_captures USING btree (created_at DESC, run_id DESC);



-- Name: market_scope_gate; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX market_scope_gate ON public.resource_right_fences USING btree (data_use_scope_id) WHERE (kind = 'data_use'::text);



-- Name: market_source_identity; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX market_source_identity ON public.source_registry USING btree (family, namespace, declared_external_id) WHERE (custody_kind = 'platform_market'::text);



-- Name: passkeys_user_id_index; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX passkeys_user_id_index ON public.passkeys USING btree (user_id);



-- Name: processing_completion_ticket_expiry; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX processing_completion_ticket_expiry ON public.market_processing_completion_tickets USING btree (expires_at);



-- Name: registration_email_proofs_expiry; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX registration_email_proofs_expiry ON public.registration_email_proofs USING btree (expires_at);



-- Name: security_action_grants_platform_active_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX security_action_grants_platform_active_unique ON public.security_action_grants USING btree (subject_user_id, action, subject_generation) WHERE (((scope_kind)::text = 'platform'::text) AND ((status)::text = 'active'::text));



-- Name: security_action_grants_subject_user_id_action_scope_kind_status; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX security_action_grants_subject_user_id_action_scope_kind_status ON public.security_action_grants USING btree (subject_user_id, action, scope_kind, status);



-- Name: security_audit_events_subject_user_id_occurred_at_index; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX security_audit_events_subject_user_id_occurred_at_index ON public.security_audit_events USING btree (subject_user_id, occurred_at);



-- Name: security_sessions_user_id_revoked_at_absolute_expires_at_index; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX security_sessions_user_id_revoked_at_absolute_expires_at_index ON public.security_sessions USING btree (user_id, revoked_at, absolute_expires_at);



-- Name: service_action_grants_active_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX service_action_grants_active_unique ON public.service_action_grants USING btree (service_identity_id, business_id, action) WHERE ((status)::text = 'active'::text);



-- Name: service_audit_events_service_identity_id_occurred_at_index; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE INDEX service_audit_events_service_identity_id_occurred_at_index ON public.service_audit_events USING btree (service_identity_id, occurred_at);



-- Name: source_right_gate; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX source_right_gate ON public.resource_right_fences USING btree (source_id) WHERE (kind = 'source'::text);



-- Name: users_email_lower_unique; Type: INDEX; Schema: public; Owner: builderad_owner
--

CREATE UNIQUE INDEX users_email_lower_unique ON public.users USING btree (lower((email)::text));



-- Name: invocations operating_mandate_effect; Type: TRIGGER; Schema: builderad_private; Owner: builderad_owner
--

CREATE TRIGGER operating_mandate_effect BEFORE INSERT ON builderad_private.invocations FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_operating_mandate_effect();



-- Name: content_articles content_articles_unique_slug_guard; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER content_articles_unique_slug_guard BEFORE INSERT OR UPDATE OF slug_ar, slug_en, deleted_at ON public.content_articles FOR EACH ROW EXECUTE FUNCTION public.builderad_content_unique_slug_guard();



-- Name: content_categories content_categories_unique_slug_guard; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER content_categories_unique_slug_guard BEFORE INSERT OR UPDATE OF slug_ar, slug_en, deleted_at ON public.content_categories FOR EACH ROW EXECUTE FUNCTION public.builderad_content_unique_slug_guard();



-- Name: content_pages content_pages_unique_slug_guard; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER content_pages_unique_slug_guard BEFORE INSERT OR UPDATE OF slug_ar, slug_en, deleted_at ON public.content_pages FOR EACH ROW EXECUTE FUNCTION public.builderad_content_unique_slug_guard();



-- Name: commercial_market_countries country_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER country_immutable BEFORE DELETE OR UPDATE ON public.commercial_market_countries FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: customer_administration_events customer_events_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER customer_events_immutable BEFORE DELETE OR UPDATE ON public.customer_administration_events FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: business_data_use_bindings declared_license_binding; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER declared_license_binding BEFORE INSERT OR UPDATE ON public.business_data_use_bindings FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_declared_data_license();



-- Name: client_data_boundaries declared_license_identity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER declared_license_identity BEFORE UPDATE ON public.client_data_boundaries FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_declared_data_license();



-- Name: boundary_subjects declared_license_subject; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER declared_license_subject BEFORE INSERT OR UPDATE ON public.boundary_subjects FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_declared_data_license();



-- Name: business_expenditure_summaries expenditure_summary_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER expenditure_summary_immutable BEFORE UPDATE ON public.business_expenditure_summaries FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_expenditure_summary();



-- Name: commercial_founder_market_notes founder_notes_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER founder_notes_immutable BEFORE DELETE OR UPDATE ON public.commercial_founder_market_notes FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: google_read_attempts google_attempt_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER google_attempt_immutable BEFORE DELETE OR UPDATE ON public.google_read_attempts FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: browser_identity_transitions identity_transition_guard; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER identity_transition_guard BEFORE INSERT OR UPDATE ON public.browser_identity_transitions FOR EACH ROW EXECUTE FUNCTION public.builderad_guard_identity_transition();



-- Name: security_commercial_market_approvals market_approval_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER market_approval_immutable BEFORE DELETE OR UPDATE ON public.security_commercial_market_approvals FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: meta_read_attempts meta_attempt_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER meta_attempt_immutable BEFORE DELETE OR UPDATE ON public.meta_read_attempts FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: object_purpose_command_receipts object_purpose_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER object_purpose_immutable BEFORE DELETE OR UPDATE ON public.object_purpose_command_receipts FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: object_purpose_revisions object_purpose_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER object_purpose_immutable BEFORE DELETE OR UPDATE ON public.object_purpose_revisions FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: provider_observation_entries observation_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER observation_immutable BEFORE DELETE OR UPDATE ON public.provider_observation_entries FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: provider_observation_runs observation_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER observation_immutable BEFORE DELETE OR UPDATE ON public.provider_observation_runs FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: security_action_grants operating_beneficiary_delegation; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER operating_beneficiary_delegation BEFORE INSERT ON public.security_action_grants FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_operating_beneficiary_delegation();



-- Name: principal_lifecycles principal_lifecycle_generation_guard; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER principal_lifecycle_generation_guard BEFORE UPDATE ON public.principal_lifecycles FOR EACH ROW EXECUTE FUNCTION public.builderad_guard_lifecycle_generation();



-- Name: provider_access_events provider_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER provider_immutable BEFORE DELETE OR UPDATE ON public.provider_access_events FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: provider_access_receipts provider_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER provider_immutable BEFORE DELETE OR UPDATE ON public.provider_access_receipts FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: commercial_market_assignability sa01_complete_assignment; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_complete_assignment BEFORE INSERT OR UPDATE ON public.commercial_market_assignability FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_assignability_complete();



-- Name: commercial_audit_obligations sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.commercial_audit_obligations FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: commercial_beneficiary_bindings sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.commercial_beneficiary_bindings FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: commercial_catalog_references sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.commercial_catalog_references FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: commercial_command_results sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.commercial_command_results FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: commercial_market_assignments sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.commercial_market_assignments FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: commercial_market_configuration_versions sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.commercial_market_configuration_versions FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: commercial_markets sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.commercial_markets FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: commercial_scopes sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.commercial_scopes FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: security_commercial_audit_custody sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.security_commercial_audit_custody FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: security_commercial_mandate_executions sa01_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER sa01_immutable BEFORE DELETE OR UPDATE ON public.security_commercial_mandate_executions FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: snapchat_read_attempts snapchat_attempt_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER snapchat_attempt_immutable BEFORE DELETE OR UPDATE ON public.snapchat_read_attempts FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: snapchat_renewal_failures snapchat_renewal_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER snapchat_renewal_immutable BEFORE DELETE OR UPDATE ON public.snapchat_renewal_failures FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: source_registry source_custody_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_immutable BEFORE UPDATE ON public.source_registry FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: provider_access source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.provider_access FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: provider_access_receipts source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.provider_access_receipts FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: provider_observation_streams source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.provider_observation_streams FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: snapchat_renewal_failures source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.snapchat_renewal_failures FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: source_audit_delivery source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.source_audit_delivery FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: source_audit_obligations source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.source_audit_obligations FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: source_capabilities source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.source_capabilities FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: source_command_receipts source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.source_command_receipts FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: source_versions source_custody_integrity; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.source_versions FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();



-- Name: security_source_audit_custody source_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_immutable BEFORE DELETE OR UPDATE ON public.security_source_audit_custody FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: source_audit_obligations source_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_immutable BEFORE DELETE OR UPDATE ON public.source_audit_obligations FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: source_command_receipts source_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_immutable BEFORE DELETE OR UPDATE ON public.source_command_receipts FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: source_versions source_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER source_immutable BEFORE DELETE OR UPDATE ON public.source_versions FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: tiktok_read_attempts tiktok_attempt_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER tiktok_attempt_immutable BEFORE DELETE OR UPDATE ON public.tiktok_read_attempts FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: x_read_attempts x_attempt_immutable; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER x_attempt_immutable BEFORE DELETE OR UPDATE ON public.x_read_attempts FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();



-- Name: source_capabilities zz_adopted_source_capability_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_adopted_source_capability_effect BEFORE INSERT OR DELETE OR UPDATE ON public.source_capabilities FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_capabilities zz_adopted_source_capability_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_adopted_source_capability_truncate BEFORE TRUNCATE ON public.source_capabilities FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_registry zz_adopted_source_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_adopted_source_effect BEFORE INSERT OR DELETE OR UPDATE ON public.source_registry FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_registry zz_adopted_source_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_adopted_source_truncate BEFORE TRUNCATE ON public.source_registry FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_practice_assessments zz_assessment_effect; Type: TRIGGER; Schema: public; Owner: builderad_scope_executor
--

CREATE TRIGGER zz_assessment_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_practice_assessments FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_practice_assessments zz_assessment_truncate; Type: TRIGGER; Schema: public; Owner: builderad_scope_executor
--

CREATE TRIGGER zz_assessment_truncate BEFORE TRUNCATE ON public.business_practice_assessments FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: authority_qualification_receipts zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.authority_qualification_receipts FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: boundary_subjects zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.boundary_subjects FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_authority_fences zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_authority_fences FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_branches zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_branches FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_command_receipts zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_command_receipts FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_context_heads zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_context_heads FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_context_transitions zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_context_transitions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_current_intents zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_current_intents FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_data_use_bindings zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_data_use_bindings FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_declared_markets zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_declared_markets FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_goal_preferences zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_goal_preferences FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_intent_transitions zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_intent_transitions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_market_transitions zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_market_transitions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_preference_transitions zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_preference_transitions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: businesses zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.businesses FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: client_data_boundaries zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.client_data_boundaries FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: data_use_scopes zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.data_use_scopes FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: material_lineage_manifests zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.material_lineage_manifests FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: material_use_dependencies zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.material_use_dependencies FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: migration_cohort_control zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.migration_cohort_control FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: migration_cohort_members zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.migration_cohort_members FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: object_purpose_business_context_refs zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.object_purpose_business_context_refs FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: resource_right_fences zz_business_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_effect BEFORE INSERT OR DELETE OR UPDATE ON public.resource_right_fences FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_business_finding_captures zz_business_finding_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_finding_effect BEFORE INSERT OR DELETE OR UPDATE ON public.market_business_finding_captures FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_business_finding_captures zz_business_finding_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_finding_truncate BEFORE TRUNCATE ON public.market_business_finding_captures FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: security_action_grants zz_business_grant_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_grant_effect BEFORE INSERT OR DELETE OR UPDATE ON public.security_action_grants FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: security_action_grants zz_business_grant_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_grant_truncate BEFORE TRUNCATE ON public.security_action_grants FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: object_purpose_command_receipts zz_business_purpose_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_purpose_effect BEFORE INSERT OR DELETE OR UPDATE ON public.object_purpose_command_receipts FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: object_purpose_revisions zz_business_purpose_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_purpose_effect BEFORE INSERT OR DELETE OR UPDATE ON public.object_purpose_revisions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: object_purposes zz_business_purpose_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_purpose_effect BEFORE INSERT OR DELETE OR UPDATE ON public.object_purposes FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: authority_qualification_receipts zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.authority_qualification_receipts FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: boundary_subjects zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.boundary_subjects FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_authority_fences zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_authority_fences FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_branches zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_branches FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_command_receipts zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_command_receipts FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_context_heads zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_context_heads FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_context_transitions zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_context_transitions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_current_intents zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_current_intents FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_data_use_bindings zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_data_use_bindings FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_declared_markets zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_declared_markets FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_goal_preferences zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_goal_preferences FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_intent_transitions zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_intent_transitions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_market_transitions zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_market_transitions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_preference_transitions zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.business_preference_transitions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: businesses zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.businesses FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: client_data_boundaries zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.client_data_boundaries FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: data_use_scopes zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.data_use_scopes FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: material_lineage_manifests zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.material_lineage_manifests FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: material_use_dependencies zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.material_use_dependencies FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: migration_cohort_control zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.migration_cohort_control FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: migration_cohort_members zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.migration_cohort_members FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: object_purpose_business_context_refs zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.object_purpose_business_context_refs FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: resource_right_fences zz_business_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.resource_right_fences FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_processing_completion_tickets zz_completion_ticket_consume; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_completion_ticket_consume BEFORE UPDATE ON public.market_processing_completion_tickets FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_expenditure_summaries zz_expenditure_summary_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_expenditure_summary_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_expenditure_summaries FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_expenditure_summaries zz_expenditure_summary_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_expenditure_summary_truncate BEFORE TRUNCATE ON public.business_expenditure_summaries FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: inquiry_evidence_enrollments zz_inquiry_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_inquiry_effect BEFORE INSERT OR DELETE OR UPDATE ON public.inquiry_evidence_enrollments FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: inquiry_evidence_heads zz_inquiry_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_inquiry_effect BEFORE INSERT OR DELETE OR UPDATE ON public.inquiry_evidence_heads FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: inquiry_evidence_revisions zz_inquiry_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_inquiry_effect BEFORE INSERT OR DELETE OR UPDATE ON public.inquiry_evidence_revisions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: inquiry_evidence_enrollments zz_inquiry_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_inquiry_truncate BEFORE TRUNCATE ON public.inquiry_evidence_enrollments FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: inquiry_evidence_heads zz_inquiry_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_inquiry_truncate BEFORE TRUNCATE ON public.inquiry_evidence_heads FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: inquiry_evidence_revisions zz_inquiry_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_inquiry_truncate BEFORE TRUNCATE ON public.inquiry_evidence_revisions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_knowledge_captures zz_knowledge_capture_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_knowledge_capture_effect BEFORE INSERT OR DELETE OR UPDATE ON public.market_knowledge_captures FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_knowledge_captures zz_knowledge_capture_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_knowledge_capture_truncate BEFORE TRUNCATE ON public.market_knowledge_captures FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: security_action_grants zz_knowledge_retention_grant; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_knowledge_retention_grant AFTER UPDATE ON public.security_action_grants FOR EACH ROW EXECUTE FUNCTION builderad_private.knowledge_retention_withdrawal();



-- Name: authority_qualification_receipts zz_knowledge_retention_qualification; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_knowledge_retention_qualification AFTER UPDATE ON public.authority_qualification_receipts FOR EACH ROW EXECUTE FUNCTION builderad_private.knowledge_retention_withdrawal();



-- Name: source_registry zz_knowledge_retention_registry; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_knowledge_retention_registry AFTER UPDATE ON public.source_registry FOR EACH ROW EXECUTE FUNCTION builderad_private.knowledge_retention_withdrawal();



-- Name: data_use_scopes zz_knowledge_retention_scope; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_knowledge_retention_scope AFTER UPDATE ON public.data_use_scopes FOR EACH ROW EXECUTE FUNCTION builderad_private.knowledge_retention_withdrawal();



-- Name: market_source_custody zz_knowledge_retention_source; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_knowledge_retention_source AFTER UPDATE ON public.market_source_custody FOR EACH ROW EXECUTE FUNCTION builderad_private.knowledge_retention_withdrawal();



-- Name: market_knowledge_completion_tickets zz_knowledge_ticket_consume; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_knowledge_ticket_consume BEFORE UPDATE ON public.market_knowledge_completion_tickets FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_evidence_heads zz_market_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_market_effect BEFORE INSERT OR DELETE OR UPDATE ON public.market_evidence_heads FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_evidence_revisions zz_market_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_market_effect BEFORE INSERT OR DELETE OR UPDATE ON public.market_evidence_revisions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_evidence_transitions zz_market_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_market_effect BEFORE INSERT OR DELETE OR UPDATE ON public.market_evidence_transitions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_source_custody zz_market_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_market_effect BEFORE INSERT OR DELETE OR UPDATE ON public.market_source_custody FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_evidence_heads zz_market_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_market_truncate BEFORE TRUNCATE ON public.market_evidence_heads FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_evidence_revisions zz_market_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_market_truncate BEFORE TRUNCATE ON public.market_evidence_revisions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_evidence_transitions zz_market_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_market_truncate BEFORE TRUNCATE ON public.market_evidence_transitions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_source_custody zz_market_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_market_truncate BEFORE TRUNCATE ON public.market_source_custody FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_audit_delivery zz_native_scope_append; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_native_scope_append BEFORE INSERT ON public.source_audit_delivery FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_audit_obligations zz_native_scope_append; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_native_scope_append BEFORE INSERT ON public.source_audit_obligations FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_command_receipts zz_native_scope_append; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_native_scope_append BEFORE INSERT ON public.source_command_receipts FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_versions zz_native_scope_append; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_native_scope_append BEFORE INSERT ON public.source_versions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_audit_delivery zz_native_scope_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_native_scope_truncate BEFORE TRUNCATE ON public.source_audit_delivery FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_audit_obligations zz_native_scope_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_native_scope_truncate BEFORE TRUNCATE ON public.source_audit_obligations FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_command_receipts zz_native_scope_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_native_scope_truncate BEFORE TRUNCATE ON public.source_command_receipts FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: source_versions zz_native_scope_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_native_scope_truncate BEFORE TRUNCATE ON public.source_versions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_practice_revisions zz_practice_effect; Type: TRIGGER; Schema: public; Owner: builderad_scope_executor
--

CREATE TRIGGER zz_practice_effect BEFORE INSERT OR DELETE OR UPDATE ON public.business_practice_revisions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: business_practice_revisions zz_practice_truncate; Type: TRIGGER; Schema: public; Owner: builderad_scope_executor
--

CREATE TRIGGER zz_practice_truncate BEFORE TRUNCATE ON public.business_practice_revisions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_processing_runs zz_processing_effect; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_processing_effect BEFORE INSERT OR DELETE OR UPDATE ON public.market_processing_runs FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: market_processing_runs zz_processing_truncate; Type: TRIGGER; Schema: public; Owner: builderad_owner
--

CREATE TRIGGER zz_processing_truncate BEFORE TRUNCATE ON public.market_processing_runs FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();



-- Name: command_permits command_permits_invocation_nonce_fkey; Type: FK CONSTRAINT; Schema: builderad_private; Owner: builderad_owner
--

ALTER TABLE ONLY builderad_private.command_permits
    ADD CONSTRAINT command_permits_invocation_nonce_fkey FOREIGN KEY (invocation_nonce) REFERENCES builderad_private.invocations(nonce) ON DELETE RESTRICT;



-- Name: invocations invocations_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: builderad_private; Owner: builderad_owner
--

ALTER TABLE ONLY builderad_private.invocations
    ADD CONSTRAINT invocations_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: authority_qualification_receipts authority_qualification_receipt_agency_membership_grant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.authority_qualification_receipts
    ADD CONSTRAINT authority_qualification_receipt_agency_membership_grant_id_fkey FOREIGN KEY (agency_membership_grant_id) REFERENCES public.security_action_grants(id) ON DELETE RESTRICT;



-- Name: authority_qualification_receipts authority_qualification_receipts_agency_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.authority_qualification_receipts
    ADD CONSTRAINT authority_qualification_receipts_agency_business_id_fkey FOREIGN KEY (agency_business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: authority_qualification_receipts authority_qualification_receipts_issuer_grant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.authority_qualification_receipts
    ADD CONSTRAINT authority_qualification_receipts_issuer_grant_id_fkey FOREIGN KEY (issuer_grant_id) REFERENCES public.security_action_grants(id) ON DELETE RESTRICT;



-- Name: authority_qualification_receipts authority_qualification_receipts_subject_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.authority_qualification_receipts
    ADD CONSTRAINT authority_qualification_receipts_subject_user_id_fkey FOREIGN KEY (subject_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: boundary_subjects boundary_subjects_boundary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.boundary_subjects
    ADD CONSTRAINT boundary_subjects_boundary_id_fkey FOREIGN KEY (boundary_id) REFERENCES public.client_data_boundaries(boundary_id) ON DELETE RESTRICT;



-- Name: boundary_subjects boundary_subjects_branch_id_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.boundary_subjects
    ADD CONSTRAINT boundary_subjects_branch_id_business_id_fkey FOREIGN KEY (branch_id, business_id) REFERENCES public.business_branches(id, business_id) ON DELETE RESTRICT;



-- Name: boundary_subjects boundary_subjects_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.boundary_subjects
    ADD CONSTRAINT boundary_subjects_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: browser_identity_transitions browser_identity_transitions_principal_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.browser_identity_transitions
    ADD CONSTRAINT browser_identity_transitions_principal_id_fkey FOREIGN KEY (principal_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_authority_fences business_authority_fences_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_authority_fences
    ADD CONSTRAINT business_authority_fences_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_branches business_branches_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_branches
    ADD CONSTRAINT business_branches_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_command_receipts business_command_receipts_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_command_receipts
    ADD CONSTRAINT business_command_receipts_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_command_receipts business_command_receipts_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_command_receipts
    ADD CONSTRAINT business_command_receipts_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_context_heads business_context_heads_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_context_heads
    ADD CONSTRAINT business_context_heads_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_context_heads business_context_heads_business_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_context_heads
    ADD CONSTRAINT business_context_heads_business_type_id_fkey FOREIGN KEY (business_type_id) REFERENCES public.business_types(id) ON DELETE RESTRICT;



-- Name: business_context_transitions business_context_transitions_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_context_transitions
    ADD CONSTRAINT business_context_transitions_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_context_transitions business_context_transitions_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_context_transitions
    ADD CONSTRAINT business_context_transitions_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_current_intents business_current_intents_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_current_intents
    ADD CONSTRAINT business_current_intents_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_current_intents business_current_intents_last_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_current_intents
    ADD CONSTRAINT business_current_intents_last_event_id_fkey FOREIGN KEY (last_event_id) REFERENCES public.business_intent_transitions(event_id) DEFERRABLE INITIALLY DEFERRED;



-- Name: business_data_use_bindings business_data_use_bindings_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_data_use_bindings
    ADD CONSTRAINT business_data_use_bindings_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_data_use_bindings business_data_use_bindings_data_use_scope_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_data_use_bindings
    ADD CONSTRAINT business_data_use_bindings_data_use_scope_id_fkey FOREIGN KEY (data_use_scope_id) REFERENCES public.data_use_scopes(scope_id) ON DELETE RESTRICT;



-- Name: business_data_use_bindings business_data_use_bindings_qualification_ref_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_data_use_bindings
    ADD CONSTRAINT business_data_use_bindings_qualification_ref_fkey FOREIGN KEY (qualification_ref) REFERENCES public.authority_qualification_receipts(qualification_id) ON DELETE RESTRICT;



-- Name: business_declared_markets business_declared_markets_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_declared_markets
    ADD CONSTRAINT business_declared_markets_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_declared_markets business_declared_markets_country_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_declared_markets
    ADD CONSTRAINT business_declared_markets_country_code_fkey FOREIGN KEY (country_code) REFERENCES public.country_references(code) ON DELETE RESTRICT;



-- Name: business_expenditure_summaries business_expenditure_summaries_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_expenditure_summaries
    ADD CONSTRAINT business_expenditure_summaries_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_expenditure_summaries business_expenditure_summaries_output_scope_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_expenditure_summaries
    ADD CONSTRAINT business_expenditure_summaries_output_scope_id_fkey FOREIGN KEY (output_scope_id) REFERENCES public.data_use_scopes(scope_id) ON DELETE RESTRICT;



-- Name: business_expenditure_summaries business_expenditure_summaries_processor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_expenditure_summaries
    ADD CONSTRAINT business_expenditure_summaries_processor_user_id_fkey FOREIGN KEY (processor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_expenditure_summaries business_expenditure_summaries_publication_manifest_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_expenditure_summaries
    ADD CONSTRAINT business_expenditure_summaries_publication_manifest_id_fkey FOREIGN KEY (publication_manifest_id) REFERENCES public.material_lineage_manifests(manifest_id) ON DELETE RESTRICT;



-- Name: business_goal_preferences business_goal_preferences_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_goal_preferences
    ADD CONSTRAINT business_goal_preferences_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_goal_preferences business_goal_preferences_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_goal_preferences
    ADD CONSTRAINT business_goal_preferences_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_intent_transitions business_intent_transitions_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_intent_transitions
    ADD CONSTRAINT business_intent_transitions_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_intent_transitions business_intent_transitions_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_intent_transitions
    ADD CONSTRAINT business_intent_transitions_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_market_transitions business_market_transitions_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_market_transitions
    ADD CONSTRAINT business_market_transitions_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_market_transitions business_market_transitions_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_market_transitions
    ADD CONSTRAINT business_market_transitions_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_practice_assessments business_practice_assessments_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_assessments
    ADD CONSTRAINT business_practice_assessments_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_practice_assessments business_practice_assessments_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_assessments
    ADD CONSTRAINT business_practice_assessments_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_practice_assessments business_practice_assessments_business_id_practice_id_prac_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_assessments
    ADD CONSTRAINT business_practice_assessments_business_id_practice_id_prac_fkey FOREIGN KEY (business_id, practice_id, practice_revision) REFERENCES public.business_practice_revisions(business_id, practice_id, revision) ON DELETE RESTRICT;



-- Name: business_practice_revisions business_practice_revisions_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_revisions
    ADD CONSTRAINT business_practice_revisions_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_practice_revisions business_practice_revisions_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_revisions
    ADD CONSTRAINT business_practice_revisions_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_practice_revisions business_practice_revisions_business_id_practice_id_correc_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE ONLY public.business_practice_revisions
    ADD CONSTRAINT business_practice_revisions_business_id_practice_id_correc_fkey FOREIGN KEY (business_id, practice_id, corrects_revision) REFERENCES public.business_practice_revisions(business_id, practice_id, revision);



-- Name: business_preference_transitions business_preference_transitions_actor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_preference_transitions
    ADD CONSTRAINT business_preference_transitions_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_preference_transitions business_preference_transitions_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_preference_transitions
    ADD CONSTRAINT business_preference_transitions_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: business_promotion_priors business_promotion_priors_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_promotion_priors
    ADD CONSTRAINT business_promotion_priors_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: business_promotion_priors business_promotion_priors_business_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_promotion_priors
    ADD CONSTRAINT business_promotion_priors_business_type_id_fkey FOREIGN KEY (business_type_id) REFERENCES public.business_types(id) ON DELETE RESTRICT;



-- Name: business_source_guidance business_source_guidance_business_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.business_source_guidance
    ADD CONSTRAINT business_source_guidance_business_type_id_fkey FOREIGN KEY (business_type_id) REFERENCES public.business_types(id) ON DELETE RESTRICT;



-- Name: businesses businesses_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.businesses
    ADD CONSTRAINT businesses_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: client_data_boundaries client_data_boundaries_qualification_ref_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.client_data_boundaries
    ADD CONSTRAINT client_data_boundaries_qualification_ref_fkey FOREIGN KEY (qualification_ref) REFERENCES public.authority_qualification_receipts(qualification_id) ON DELETE RESTRICT;



-- Name: commercial_audit_delivery commercial_audit_delivery_obligation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_audit_delivery
    ADD CONSTRAINT commercial_audit_delivery_obligation_id_fkey FOREIGN KEY (obligation_id) REFERENCES public.commercial_audit_obligations(id) ON DELETE RESTRICT;



-- Name: commercial_audit_obligations commercial_audit_obligations_command_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_audit_obligations
    ADD CONSTRAINT commercial_audit_obligations_command_id_fkey FOREIGN KEY (command_id) REFERENCES public.commercial_command_results(command_id) ON DELETE RESTRICT;



-- Name: commercial_audit_obligations commercial_audit_obligations_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_audit_obligations
    ADD CONSTRAINT commercial_audit_obligations_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: commercial_beneficiary_bindings commercial_beneficiary_bindings_scope_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_beneficiary_bindings
    ADD CONSTRAINT commercial_beneficiary_bindings_scope_id_fkey FOREIGN KEY (scope_id) REFERENCES public.commercial_scopes(id) ON DELETE RESTRICT;



-- Name: commercial_beneficiary_bindings commercial_beneficiary_bindings_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_beneficiary_bindings
    ADD CONSTRAINT commercial_beneficiary_bindings_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: commercial_catalog_references commercial_catalog_references_market_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_catalog_references
    ADD CONSTRAINT commercial_catalog_references_market_id_fkey FOREIGN KEY (market_id) REFERENCES public.commercial_markets(id) ON DELETE RESTRICT;



-- Name: commercial_command_results commercial_command_results_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_command_results
    ADD CONSTRAINT commercial_command_results_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: commercial_command_results commercial_command_results_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_command_results
    ADD CONSTRAINT commercial_command_results_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: commercial_founder_market_notes commercial_founder_market_notes_command_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_founder_market_notes
    ADD CONSTRAINT commercial_founder_market_notes_command_id_fkey FOREIGN KEY (command_id) REFERENCES public.commercial_command_results(command_id) ON DELETE RESTRICT;



-- Name: commercial_market_assignability commercial_market_assignability_configuration_id_market_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_assignability
    ADD CONSTRAINT commercial_market_assignability_configuration_id_market_id_fkey FOREIGN KEY (configuration_id, market_id) REFERENCES public.commercial_market_configuration_versions(id, market_id) ON DELETE RESTRICT;



-- Name: commercial_market_assignability commercial_market_assignability_market_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_assignability
    ADD CONSTRAINT commercial_market_assignability_market_id_fkey FOREIGN KEY (market_id) REFERENCES public.commercial_markets(id) ON DELETE RESTRICT;



-- Name: commercial_market_assignments commercial_market_assignments_configuration_id_market_id_c_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_assignments
    ADD CONSTRAINT commercial_market_assignments_configuration_id_market_id_c_fkey FOREIGN KEY (configuration_id, market_id, catalog_reference_id, denomination_code) REFERENCES public.commercial_market_configuration_versions(id, market_id, catalog_reference_id, denomination_code) ON DELETE RESTRICT;



-- Name: commercial_market_assignments commercial_market_assignments_scope_id_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_assignments
    ADD CONSTRAINT commercial_market_assignments_scope_id_business_id_fkey FOREIGN KEY (scope_id, business_id) REFERENCES public.commercial_beneficiary_bindings(scope_id, business_id) ON DELETE RESTRICT;



-- Name: commercial_market_configuration_versions commercial_market_configurati_catalog_reference_id_market__fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_configuration_versions
    ADD CONSTRAINT commercial_market_configurati_catalog_reference_id_market__fkey FOREIGN KEY (catalog_reference_id, market_id) REFERENCES public.commercial_catalog_references(id, market_id) ON DELETE RESTRICT;



-- Name: commercial_market_configuration_versions commercial_market_configuration_versions_market_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_configuration_versions
    ADD CONSTRAINT commercial_market_configuration_versions_market_id_fkey FOREIGN KEY (market_id) REFERENCES public.commercial_markets(id) ON DELETE RESTRICT;



-- Name: commercial_market_countries commercial_market_countries_command_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_countries
    ADD CONSTRAINT commercial_market_countries_command_id_fkey FOREIGN KEY (command_id) REFERENCES public.commercial_command_results(command_id) ON DELETE RESTRICT;



-- Name: commercial_market_countries commercial_market_countries_market_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.commercial_market_countries
    ADD CONSTRAINT commercial_market_countries_market_id_fkey FOREIGN KEY (market_id) REFERENCES public.commercial_markets(id) ON DELETE RESTRICT;



-- Name: content_article_revisions content_article_revisions_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_article_revisions
    ADD CONSTRAINT content_article_revisions_article_id_fkey FOREIGN KEY (article_id) REFERENCES public.content_articles(id) ON DELETE CASCADE;



-- Name: content_article_revisions content_article_revisions_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_article_revisions
    ADD CONSTRAINT content_article_revisions_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.content_categories(id) ON DELETE RESTRICT;



-- Name: content_article_revisions content_article_revisions_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_article_revisions
    ADD CONSTRAINT content_article_revisions_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;



-- Name: content_articles content_articles_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_articles
    ADD CONSTRAINT content_articles_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.content_categories(id) ON DELETE RESTRICT;



-- Name: content_articles content_articles_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_articles
    ADD CONSTRAINT content_articles_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;



-- Name: content_articles content_articles_draft_revision_fk; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_articles
    ADD CONSTRAINT content_articles_draft_revision_fk FOREIGN KEY (draft_revision_id) REFERENCES public.content_article_revisions(id) ON DELETE SET NULL;



-- Name: content_articles content_articles_published_revision_fk; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_articles
    ADD CONSTRAINT content_articles_published_revision_fk FOREIGN KEY (published_revision_id) REFERENCES public.content_article_revisions(id) ON DELETE SET NULL;



-- Name: content_footer_settings content_footer_settings_published_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_footer_settings
    ADD CONSTRAINT content_footer_settings_published_by_fkey FOREIGN KEY (published_by) REFERENCES public.users(id) ON DELETE SET NULL;



-- Name: content_navigation_links content_navigation_links_article_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_links
    ADD CONSTRAINT content_navigation_links_article_id_fkey FOREIGN KEY (article_id) REFERENCES public.content_articles(id) ON DELETE RESTRICT;



-- Name: content_navigation_links content_navigation_links_page_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_links
    ADD CONSTRAINT content_navigation_links_page_id_fkey FOREIGN KEY (page_id) REFERENCES public.content_pages(id) ON DELETE RESTRICT;



-- Name: content_navigation_links content_navigation_links_section_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_navigation_links
    ADD CONSTRAINT content_navigation_links_section_id_fkey FOREIGN KEY (section_id) REFERENCES public.content_navigation_sections(id) ON DELETE CASCADE;



-- Name: content_page_revisions content_page_revisions_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_page_revisions
    ADD CONSTRAINT content_page_revisions_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;



-- Name: content_page_revisions content_page_revisions_page_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_page_revisions
    ADD CONSTRAINT content_page_revisions_page_id_fkey FOREIGN KEY (page_id) REFERENCES public.content_pages(id) ON DELETE CASCADE;



-- Name: content_pages content_pages_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_pages
    ADD CONSTRAINT content_pages_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;



-- Name: content_pages content_pages_draft_revision_fk; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_pages
    ADD CONSTRAINT content_pages_draft_revision_fk FOREIGN KEY (draft_revision_id) REFERENCES public.content_page_revisions(id) ON DELETE SET NULL;



-- Name: content_pages content_pages_published_revision_fk; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.content_pages
    ADD CONSTRAINT content_pages_published_revision_fk FOREIGN KEY (published_revision_id) REFERENCES public.content_page_revisions(id) ON DELETE SET NULL;



-- Name: customer_administration_events customer_administration_events_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.customer_administration_events
    ADD CONSTRAINT customer_administration_events_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id);



-- Name: customer_administration_events customer_administration_events_subject_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.customer_administration_events
    ADD CONSTRAINT customer_administration_events_subject_user_id_fkey FOREIGN KEY (subject_user_id) REFERENCES public.users(id);



-- Name: customer_administration_events customer_administration_events_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.customer_administration_events
    ADD CONSTRAINT customer_administration_events_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id);



-- Name: data_use_scopes data_use_scopes_boundary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.data_use_scopes
    ADD CONSTRAINT data_use_scopes_boundary_id_fkey FOREIGN KEY (boundary_id) REFERENCES public.client_data_boundaries(boundary_id) ON DELETE RESTRICT;



-- Name: data_use_scopes data_use_scopes_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.data_use_scopes
    ADD CONSTRAINT data_use_scopes_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.source_registry(id) ON DELETE RESTRICT;



-- Name: global_appearance global_appearance_published_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.global_appearance
    ADD CONSTRAINT global_appearance_published_by_fkey FOREIGN KEY (published_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: google_read_attempts google_read_attempts_namespace_generation_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.google_read_attempts
    ADD CONSTRAINT google_read_attempts_namespace_generation_fkey FOREIGN KEY (namespace, generation) REFERENCES public.provider_observation_runs(namespace, generation);



-- Name: security_action_grants grant_boundary_fk; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants
    ADD CONSTRAINT grant_boundary_fk FOREIGN KEY (boundary_id) REFERENCES public.client_data_boundaries(boundary_id) ON DELETE RESTRICT;



-- Name: security_action_grants grant_data_use_fk; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants
    ADD CONSTRAINT grant_data_use_fk FOREIGN KEY (data_use_scope_id) REFERENCES public.data_use_scopes(scope_id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_enrollments inquiry_evidence_enrollments_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_enrollments
    ADD CONSTRAINT inquiry_evidence_enrollments_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_enrollments inquiry_evidence_enrollments_basis_qualification_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_enrollments
    ADD CONSTRAINT inquiry_evidence_enrollments_basis_qualification_id_fkey FOREIGN KEY (basis_qualification_id) REFERENCES public.authority_qualification_receipts(qualification_id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_enrollments inquiry_evidence_enrollments_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_enrollments
    ADD CONSTRAINT inquiry_evidence_enrollments_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_enrollments inquiry_evidence_enrollments_qualification_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_enrollments
    ADD CONSTRAINT inquiry_evidence_enrollments_qualification_id_fkey FOREIGN KEY (qualification_id) REFERENCES public.authority_qualification_receipts(qualification_id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_heads inquiry_evidence_heads_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_heads
    ADD CONSTRAINT inquiry_evidence_heads_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_heads inquiry_evidence_heads_issuer_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_heads
    ADD CONSTRAINT inquiry_evidence_heads_issuer_user_id_fkey FOREIGN KEY (issuer_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_revisions inquiry_evidence_revisions_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_revisions
    ADD CONSTRAINT inquiry_evidence_revisions_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_revisions inquiry_evidence_revisions_declaration_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_revisions
    ADD CONSTRAINT inquiry_evidence_revisions_declaration_id_fkey FOREIGN KEY (declaration_id) REFERENCES public.inquiry_evidence_heads(declaration_id) ON DELETE RESTRICT;



-- Name: inquiry_evidence_revisions inquiry_evidence_revisions_qualification_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.inquiry_evidence_revisions
    ADD CONSTRAINT inquiry_evidence_revisions_qualification_id_fkey FOREIGN KEY (qualification_id) REFERENCES public.inquiry_evidence_enrollments(qualification_id) ON DELETE RESTRICT;



-- Name: internal_personnel internal_personnel_attested_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.internal_personnel
    ADD CONSTRAINT internal_personnel_attested_by_foreign FOREIGN KEY (attested_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: internal_personnel internal_personnel_factor_independence_attested_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.internal_personnel
    ADD CONSTRAINT internal_personnel_factor_independence_attested_by_foreign FOREIGN KEY (factor_independence_attested_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: internal_personnel internal_personnel_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.internal_personnel
    ADD CONSTRAINT internal_personnel_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: market_business_finding_captures market_business_finding_captures_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_business_finding_captures
    ADD CONSTRAINT market_business_finding_captures_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: market_business_finding_captures market_business_finding_captures_output_scope_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_business_finding_captures
    ADD CONSTRAINT market_business_finding_captures_output_scope_id_fkey FOREIGN KEY (output_scope_id) REFERENCES public.data_use_scopes(scope_id) ON DELETE RESTRICT;



-- Name: market_business_finding_captures market_business_finding_captures_processing_run_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_business_finding_captures
    ADD CONSTRAINT market_business_finding_captures_processing_run_id_fkey FOREIGN KEY (processing_run_id) REFERENCES public.market_processing_runs(run_id) ON DELETE RESTRICT;



-- Name: market_business_finding_captures market_business_finding_captures_processor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_business_finding_captures
    ADD CONSTRAINT market_business_finding_captures_processor_user_id_fkey FOREIGN KEY (processor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: market_business_finding_captures market_business_finding_captures_publication_manifest_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_business_finding_captures
    ADD CONSTRAINT market_business_finding_captures_publication_manifest_id_fkey FOREIGN KEY (publication_manifest_id) REFERENCES public.material_lineage_manifests(manifest_id) ON DELETE RESTRICT;



-- Name: market_evidence_heads market_evidence_heads_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_heads
    ADD CONSTRAINT market_evidence_heads_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.source_registry(id) ON DELETE RESTRICT;



-- Name: market_evidence_revisions market_evidence_revisions_evidence_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_revisions
    ADD CONSTRAINT market_evidence_revisions_evidence_id_fkey FOREIGN KEY (evidence_id) REFERENCES public.market_evidence_heads(evidence_id) ON DELETE RESTRICT;



-- Name: market_evidence_transitions market_evidence_transitions_evidence_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_transitions
    ADD CONSTRAINT market_evidence_transitions_evidence_id_fkey FOREIGN KEY (evidence_id) REFERENCES public.market_evidence_heads(evidence_id) ON DELETE RESTRICT;



-- Name: market_evidence_transitions market_evidence_transitions_evidence_id_to_revision_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_evidence_transitions
    ADD CONSTRAINT market_evidence_transitions_evidence_id_to_revision_fkey FOREIGN KEY (evidence_id, to_revision) REFERENCES public.market_evidence_revisions(evidence_id, revision) ON DELETE RESTRICT;



-- Name: market_knowledge_captures market_knowledge_captures_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_knowledge_captures
    ADD CONSTRAINT market_knowledge_captures_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: market_knowledge_captures market_knowledge_captures_output_scope_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_knowledge_captures
    ADD CONSTRAINT market_knowledge_captures_output_scope_id_fkey FOREIGN KEY (output_scope_id) REFERENCES public.data_use_scopes(scope_id) ON DELETE RESTRICT;



-- Name: market_knowledge_captures market_knowledge_captures_publication_manifest_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_knowledge_captures
    ADD CONSTRAINT market_knowledge_captures_publication_manifest_id_fkey FOREIGN KEY (publication_manifest_id) REFERENCES public.material_lineage_manifests(manifest_id) ON DELETE RESTRICT;



-- Name: market_knowledge_completion_tickets market_knowledge_completion_tickets_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_knowledge_completion_tickets
    ADD CONSTRAINT market_knowledge_completion_tickets_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: market_knowledge_completion_tickets market_knowledge_completion_tickets_run_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_knowledge_completion_tickets
    ADD CONSTRAINT market_knowledge_completion_tickets_run_id_fkey FOREIGN KEY (run_id) REFERENCES public.market_knowledge_captures(run_id) ON DELETE RESTRICT;



-- Name: market_processing_completion_tickets market_processing_completion_tickets_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_processing_completion_tickets
    ADD CONSTRAINT market_processing_completion_tickets_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: market_processing_completion_tickets market_processing_completion_tickets_business_public_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_processing_completion_tickets
    ADD CONSTRAINT market_processing_completion_tickets_business_public_id_fkey FOREIGN KEY (business_public_id) REFERENCES public.businesses(public_id) ON DELETE RESTRICT;



-- Name: market_processing_completion_tickets market_processing_completion_tickets_run_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_processing_completion_tickets
    ADD CONSTRAINT market_processing_completion_tickets_run_id_fkey FOREIGN KEY (run_id) REFERENCES public.market_processing_runs(run_id) ON DELETE RESTRICT;



-- Name: market_processing_runs market_processing_runs_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_processing_runs
    ADD CONSTRAINT market_processing_runs_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: market_processing_runs market_processing_runs_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_processing_runs
    ADD CONSTRAINT market_processing_runs_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: market_source_custody market_source_custody_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.market_source_custody
    ADD CONSTRAINT market_source_custody_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.source_registry(id) ON DELETE RESTRICT;



-- Name: material_use_dependencies material_use_dependencies_data_use_scope_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.material_use_dependencies
    ADD CONSTRAINT material_use_dependencies_data_use_scope_id_fkey FOREIGN KEY (data_use_scope_id) REFERENCES public.data_use_scopes(scope_id) ON DELETE RESTRICT;



-- Name: meta_read_attempts meta_read_attempts_namespace_generation_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.meta_read_attempts
    ADD CONSTRAINT meta_read_attempts_namespace_generation_fkey FOREIGN KEY (namespace, generation) REFERENCES public.provider_observation_runs(namespace, generation);



-- Name: migration_cohort_members migration_cohort_members_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.migration_cohort_members
    ADD CONSTRAINT migration_cohort_members_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: migration_cohort_members migration_cohort_members_cohort_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.migration_cohort_members
    ADD CONSTRAINT migration_cohort_members_cohort_id_fkey FOREIGN KEY (cohort_id) REFERENCES public.migration_cohort_control(cohort_id) ON DELETE RESTRICT;



-- Name: object_purpose_business_context_refs object_purpose_business_context_refs_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_business_context_refs
    ADD CONSTRAINT object_purpose_business_context_refs_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: object_purpose_business_context_refs object_purpose_business_context_refs_native_revision_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_business_context_refs
    ADD CONSTRAINT object_purpose_business_context_refs_native_revision_id_fkey FOREIGN KEY (native_revision_id) REFERENCES public.object_purpose_revisions(revision_id) ON DELETE RESTRICT;



-- Name: object_purpose_command_receipts object_purpose_command_receip_purpose_id_revision_id_busin_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_command_receipts
    ADD CONSTRAINT object_purpose_command_receip_purpose_id_revision_id_busin_fkey FOREIGN KEY (purpose_id, revision_id, business_id) REFERENCES public.object_purpose_revisions(purpose_id, revision_id, business_id) ON DELETE RESTRICT;



-- Name: object_purpose_command_receipts object_purpose_command_receipts_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_command_receipts
    ADD CONSTRAINT object_purpose_command_receipts_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id);



-- Name: object_purpose_command_receipts object_purpose_command_receipts_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_command_receipts
    ADD CONSTRAINT object_purpose_command_receipts_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: object_purpose_revisions object_purpose_revisions_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_revisions
    ADD CONSTRAINT object_purpose_revisions_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id);



-- Name: object_purpose_revisions object_purpose_revisions_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_revisions
    ADD CONSTRAINT object_purpose_revisions_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: object_purpose_revisions object_purpose_revisions_purpose_id_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_revisions
    ADD CONSTRAINT object_purpose_revisions_purpose_id_business_id_fkey FOREIGN KEY (purpose_id, business_id) REFERENCES public.object_purposes(id, business_id) ON DELETE RESTRICT;



-- Name: object_purpose_revisions object_purpose_revisions_purpose_id_command_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purpose_revisions
    ADD CONSTRAINT object_purpose_revisions_purpose_id_command_id_fkey FOREIGN KEY (purpose_id, command_id) REFERENCES public.object_purpose_command_receipts(purpose_id, command_id) DEFERRABLE INITIALLY DEFERRED;



-- Name: object_purposes object_purposes_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.object_purposes
    ADD CONSTRAINT object_purposes_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: passkeys passkeys_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.passkeys
    ADD CONSTRAINT passkeys_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: platform_maintenance_commands platform_maintenance_commands_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.platform_maintenance_commands
    ADD CONSTRAINT platform_maintenance_commands_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: principal_lifecycles principal_lifecycles_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.principal_lifecycles
    ADD CONSTRAINT principal_lifecycles_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: provider_access_events provider_access_events_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_access_events
    ADD CONSTRAINT provider_access_events_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: provider_access_events provider_access_events_namespace_command_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_access_events
    ADD CONSTRAINT provider_access_events_namespace_command_id_fkey FOREIGN KEY (namespace, command_id) REFERENCES public.provider_access_receipts(namespace, command_id) ON DELETE RESTRICT;



-- Name: provider_access_receipts provider_access_receipts_source_id_provider_resource_opera_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_access_receipts
    ADD CONSTRAINT provider_access_receipts_source_id_provider_resource_opera_fkey FOREIGN KEY (source_id, provider, resource, operation, purpose) REFERENCES public.provider_access(source_id, provider, resource, operation, purpose) ON DELETE RESTRICT;



-- Name: provider_access provider_access_source_id_resource_operation_purpose_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_access
    ADD CONSTRAINT provider_access_source_id_resource_operation_purpose_fkey FOREIGN KEY (source_id, resource, operation, purpose) REFERENCES public.source_capabilities(source_id, resource, operation, purpose) ON DELETE RESTRICT;



-- Name: provider_observation_entries provider_observation_entries_namespace_generation_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_entries
    ADD CONSTRAINT provider_observation_entries_namespace_generation_fkey FOREIGN KEY (namespace, generation) REFERENCES public.provider_observation_runs(namespace, generation);



-- Name: provider_observation_entries provider_observation_entries_namespace_prior_generation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_entries
    ADD CONSTRAINT provider_observation_entries_namespace_prior_generation_id_fkey FOREIGN KEY (namespace, prior_generation, identity) REFERENCES public.provider_observation_entries(namespace, generation, identity);



-- Name: provider_observation_runs provider_observation_runs_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_runs
    ADD CONSTRAINT provider_observation_runs_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id);



-- Name: provider_observation_runs provider_observation_runs_namespace_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_runs
    ADD CONSTRAINT provider_observation_runs_namespace_fkey FOREIGN KEY (namespace) REFERENCES public.provider_observation_streams(namespace);



-- Name: provider_observation_streams provider_observation_streams_source_id_resource_operation__fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.provider_observation_streams
    ADD CONSTRAINT provider_observation_streams_source_id_resource_operation__fkey FOREIGN KEY (source_id, resource, operation, purpose) REFERENCES public.source_capabilities(source_id, resource, operation, purpose) ON DELETE RESTRICT;



-- Name: registration_email_proofs registration_email_proofs_lineage_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.registration_email_proofs
    ADD CONSTRAINT registration_email_proofs_lineage_id_fkey FOREIGN KEY (lineage_id) REFERENCES public.browser_identity_transitions(lineage_id) ON DELETE CASCADE;



-- Name: registration_email_proofs registration_email_proofs_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.registration_email_proofs
    ADD CONSTRAINT registration_email_proofs_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;



-- Name: resource_right_fences resource_right_fences_boundary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.resource_right_fences
    ADD CONSTRAINT resource_right_fences_boundary_id_fkey FOREIGN KEY (boundary_id) REFERENCES public.client_data_boundaries(boundary_id) ON DELETE RESTRICT;



-- Name: resource_right_fences resource_right_fences_data_use_scope_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.resource_right_fences
    ADD CONSTRAINT resource_right_fences_data_use_scope_id_fkey FOREIGN KEY (data_use_scope_id) REFERENCES public.data_use_scopes(scope_id) ON DELETE RESTRICT;



-- Name: resource_right_fences resource_right_fences_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.resource_right_fences
    ADD CONSTRAINT resource_right_fences_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.source_registry(id) ON DELETE RESTRICT;



-- Name: security_action_grants security_action_grants_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants
    ADD CONSTRAINT security_action_grants_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: security_action_grants security_action_grants_granted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants
    ADD CONSTRAINT security_action_grants_granted_by_foreign FOREIGN KEY (granted_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: security_action_grants security_action_grants_parent_grant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants
    ADD CONSTRAINT security_action_grants_parent_grant_id_fkey FOREIGN KEY (parent_grant_id) REFERENCES public.security_action_grants(id) ON DELETE RESTRICT;



-- Name: security_action_grants security_action_grants_qualification_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants
    ADD CONSTRAINT security_action_grants_qualification_id_fkey FOREIGN KEY (qualification_id) REFERENCES public.authority_qualification_receipts(qualification_id) ON DELETE RESTRICT;



-- Name: security_action_grants security_action_grants_subject_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_action_grants
    ADD CONSTRAINT security_action_grants_subject_user_id_foreign FOREIGN KEY (subject_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: security_audit_events security_audit_events_actor_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_audit_events
    ADD CONSTRAINT security_audit_events_actor_user_id_foreign FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: security_audit_events security_audit_events_subject_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_audit_events
    ADD CONSTRAINT security_audit_events_subject_user_id_foreign FOREIGN KEY (subject_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: security_commercial_mandate_executions security_commercial_mandate_executions_grant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_mandate_executions
    ADD CONSTRAINT security_commercial_mandate_executions_grant_id_fkey FOREIGN KEY (grant_id) REFERENCES public.security_action_grants(id) ON DELETE RESTRICT;



-- Name: security_commercial_market_approvals security_commercial_market_approvals_administrator_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_market_approvals
    ADD CONSTRAINT security_commercial_market_approvals_administrator_id_fkey FOREIGN KEY (administrator_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: security_commercial_market_approvals security_commercial_market_approvals_founder_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_market_approvals
    ADD CONSTRAINT security_commercial_market_approvals_founder_id_fkey FOREIGN KEY (founder_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: security_commercial_market_approvals security_commercial_market_approvals_grant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_commercial_market_approvals
    ADD CONSTRAINT security_commercial_market_approvals_grant_id_fkey FOREIGN KEY (grant_id) REFERENCES public.security_action_grants(id) ON DELETE RESTRICT;



-- Name: security_sessions security_sessions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.security_sessions
    ADD CONSTRAINT security_sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: service_action_grants service_action_grants_granted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_action_grants
    ADD CONSTRAINT service_action_grants_granted_by_foreign FOREIGN KEY (granted_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: service_action_grants service_action_grants_service_identity_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_action_grants
    ADD CONSTRAINT service_action_grants_service_identity_id_foreign FOREIGN KEY (service_identity_id) REFERENCES public.service_identities(id) ON DELETE RESTRICT;



-- Name: service_action_grants service_action_grants_business_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_action_grants
    ADD CONSTRAINT service_action_grants_business_id_foreign FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: service_audit_events service_audit_events_actor_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_audit_events
    ADD CONSTRAINT service_audit_events_actor_user_id_foreign FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: service_audit_events service_audit_events_service_identity_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_audit_events
    ADD CONSTRAINT service_audit_events_service_identity_id_foreign FOREIGN KEY (service_identity_id) REFERENCES public.service_identities(id) ON DELETE RESTRICT;



-- Name: service_audit_events service_audit_events_business_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_audit_events
    ADD CONSTRAINT service_audit_events_business_id_foreign FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: service_identities service_identities_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_identities
    ADD CONSTRAINT service_identities_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: service_identities service_identities_revoked_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_identities
    ADD CONSTRAINT service_identities_revoked_by_foreign FOREIGN KEY (revoked_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: service_business_scopes service_business_scopes_authorized_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_business_scopes
    ADD CONSTRAINT service_business_scopes_authorized_by_foreign FOREIGN KEY (authorized_by) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: service_business_scopes service_business_scopes_service_identity_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_business_scopes
    ADD CONSTRAINT service_business_scopes_service_identity_id_foreign FOREIGN KEY (service_identity_id) REFERENCES public.service_identities(id) ON DELETE RESTRICT;



-- Name: service_business_scopes service_business_scopes_business_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.service_business_scopes
    ADD CONSTRAINT service_business_scopes_business_id_foreign FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: snapchat_read_attempts snapchat_read_attempts_namespace_generation_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.snapchat_read_attempts
    ADD CONSTRAINT snapchat_read_attempts_namespace_generation_fkey FOREIGN KEY (namespace, generation) REFERENCES public.provider_observation_runs(namespace, generation);



-- Name: snapchat_renewal_failures snapchat_renewal_failures_source_id_resource_operation_pur_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.snapchat_renewal_failures
    ADD CONSTRAINT snapchat_renewal_failures_source_id_resource_operation_pur_fkey FOREIGN KEY (source_id, resource, operation, purpose) REFERENCES public.source_capabilities(source_id, resource, operation, purpose) ON DELETE RESTRICT;



-- Name: source_audit_delivery source_audit_delivery_obligation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_audit_delivery
    ADD CONSTRAINT source_audit_delivery_obligation_id_fkey FOREIGN KEY (obligation_id) REFERENCES public.source_audit_obligations(id) ON DELETE RESTRICT;



-- Name: source_audit_obligations source_audit_obligations_source_id_command_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_audit_obligations
    ADD CONSTRAINT source_audit_obligations_source_id_command_id_fkey FOREIGN KEY (source_id, command_id) REFERENCES public.source_command_receipts(source_id, command_id) ON DELETE RESTRICT;



-- Name: source_capabilities source_capabilities_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_capabilities
    ADD CONSTRAINT source_capabilities_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.source_registry(id) ON DELETE RESTRICT;



-- Name: source_command_receipts source_command_receipts_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_command_receipts
    ADD CONSTRAINT source_command_receipts_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;



-- Name: source_command_receipts source_command_receipts_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_command_receipts
    ADD CONSTRAINT source_command_receipts_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.source_registry(id) ON DELETE RESTRICT;



-- Name: source_registry source_registry_business_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_registry
    ADD CONSTRAINT source_registry_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.businesses(id) ON DELETE RESTRICT;



-- Name: source_versions source_versions_source_id_command_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.source_versions
    ADD CONSTRAINT source_versions_source_id_command_id_fkey FOREIGN KEY (source_id, command_id) REFERENCES public.source_command_receipts(source_id, command_id) ON DELETE RESTRICT;



-- Name: tiktok_read_attempts tiktok_read_attempts_namespace_generation_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.tiktok_read_attempts
    ADD CONSTRAINT tiktok_read_attempts_namespace_generation_fkey FOREIGN KEY (namespace, generation) REFERENCES public.provider_observation_runs(namespace, generation);



-- Name: x_read_attempts x_read_attempts_namespace_generation_fkey; Type: FK CONSTRAINT; Schema: public; Owner: builderad_owner
--

ALTER TABLE ONLY public.x_read_attempts
    ADD CONSTRAINT x_read_attempts_namespace_generation_fkey FOREIGN KEY (namespace, generation) REFERENCES public.provider_observation_runs(namespace, generation);



-- Name: command_permits; Type: ROW SECURITY; Schema: builderad_private; Owner: builderad_owner
--

ALTER TABLE builderad_private.command_permits ENABLE ROW LEVEL SECURITY;


-- Name: invocations; Type: ROW SECURITY; Schema: builderad_private; Owner: builderad_owner
--

ALTER TABLE builderad_private.invocations ENABLE ROW LEVEL SECURITY;


-- Name: command_permits private_executor; Type: POLICY; Schema: builderad_private; Owner: builderad_owner
--

CREATE POLICY private_executor ON builderad_private.command_permits TO builderad_scope_executor USING (true) WITH CHECK (true);



-- Name: invocations private_executor; Type: POLICY; Schema: builderad_private; Owner: builderad_owner
--

CREATE POLICY private_executor ON builderad_private.invocations TO builderad_scope_executor USING (true) WITH CHECK (true);



-- Name: command_permits private_owner; Type: POLICY; Schema: builderad_private; Owner: builderad_owner
--

CREATE POLICY private_owner ON builderad_private.command_permits TO builderad_owner USING (true) WITH CHECK (true);



-- Name: invocations private_owner; Type: POLICY; Schema: builderad_private; Owner: builderad_owner
--

CREATE POLICY private_owner ON builderad_private.invocations TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_practice_assessments assessment_insert; Type: POLICY; Schema: public; Owner: builderad_scope_executor
--

CREATE POLICY assessment_insert ON public.business_practice_assessments FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_practice_assessments'::regclass, 'insert'::text, to_jsonb(business_practice_assessments.*)));



-- Name: business_practice_assessments assessment_owner; Type: POLICY; Schema: public; Owner: builderad_scope_executor
--

CREATE POLICY assessment_owner ON public.business_practice_assessments TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_practice_assessments assessment_read; Type: POLICY; Schema: public; Owner: builderad_scope_executor
--

CREATE POLICY assessment_read ON public.business_practice_assessments FOR SELECT TO builderad_scope_executor USING (true);



-- Name: commercial_market_assignability assignability_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY assignability_read ON public.commercial_market_assignability FOR SELECT TO builderad_app USING (public.builderad_sa01_has('ReadCommercialMarketRegistry'::text, market_id));



-- Name: commercial_market_assignments assignment_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY assignment_read ON public.commercial_market_assignments FOR SELECT TO builderad_app USING (public.builderad_sa01_context_visible(scope_id, business_id));



-- Name: authority_qualification_receipts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.authority_qualification_receipts ENABLE ROW LEVEL SECURITY;


-- Name: commercial_beneficiary_bindings binding_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY binding_read ON public.commercial_beneficiary_bindings FOR SELECT TO builderad_app USING (public.builderad_sa01_context_visible(scope_id, business_id));



-- Name: boundary_subjects; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.boundary_subjects ENABLE ROW LEVEL SECURITY;


-- Name: business_authority_fences; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_authority_fences ENABLE ROW LEVEL SECURITY;


-- Name: business_branches; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_branches ENABLE ROW LEVEL SECURITY;


-- Name: business_command_receipts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_command_receipts ENABLE ROW LEVEL SECURITY;


-- Name: business_context_heads; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_context_heads ENABLE ROW LEVEL SECURITY;


-- Name: business_context_transitions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_context_transitions ENABLE ROW LEVEL SECURITY;


-- Name: business_current_intents; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_current_intents ENABLE ROW LEVEL SECURITY;


-- Name: business_data_use_bindings; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_data_use_bindings ENABLE ROW LEVEL SECURITY;


-- Name: business_declared_markets; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_declared_markets ENABLE ROW LEVEL SECURITY;


-- Name: authority_qualification_receipts business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.authority_qualification_receipts FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.authority_qualification_receipts'::regclass, 'insert'::text, to_jsonb(authority_qualification_receipts.*)));



-- Name: boundary_subjects business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.boundary_subjects FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.boundary_subjects'::regclass, 'insert'::text, to_jsonb(boundary_subjects.*)));



-- Name: business_authority_fences business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_authority_fences FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_authority_fences'::regclass, 'insert'::text, to_jsonb(business_authority_fences.*)));



-- Name: business_branches business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_branches FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_branches'::regclass, 'insert'::text, to_jsonb(business_branches.*)));



-- Name: business_command_receipts business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_command_receipts FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_command_receipts'::regclass, 'insert'::text, to_jsonb(business_command_receipts.*)));



-- Name: business_context_heads business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_context_heads FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_context_heads'::regclass, 'insert'::text, to_jsonb(business_context_heads.*)));



-- Name: business_context_transitions business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_context_transitions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_context_transitions'::regclass, 'insert'::text, to_jsonb(business_context_transitions.*)));



-- Name: business_current_intents business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_current_intents FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_current_intents'::regclass, 'insert'::text, to_jsonb(business_current_intents.*)));



-- Name: business_data_use_bindings business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_data_use_bindings FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_data_use_bindings'::regclass, 'insert'::text, to_jsonb(business_data_use_bindings.*)));



-- Name: business_declared_markets business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_declared_markets FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_declared_markets'::regclass, 'insert'::text, to_jsonb(business_declared_markets.*)));



-- Name: business_goal_preferences business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_goal_preferences FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_goal_preferences'::regclass, 'insert'::text, to_jsonb(business_goal_preferences.*)));



-- Name: business_intent_transitions business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_intent_transitions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_intent_transitions'::regclass, 'insert'::text, to_jsonb(business_intent_transitions.*)));



-- Name: business_market_transitions business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_market_transitions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_market_transitions'::regclass, 'insert'::text, to_jsonb(business_market_transitions.*)));



-- Name: business_preference_transitions business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.business_preference_transitions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_preference_transitions'::regclass, 'insert'::text, to_jsonb(business_preference_transitions.*)));



-- Name: businesses business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.businesses FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.businesses'::regclass, 'insert'::text, to_jsonb(businesses.*)));



-- Name: client_data_boundaries business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.client_data_boundaries FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.client_data_boundaries'::regclass, 'insert'::text, to_jsonb(client_data_boundaries.*)));



-- Name: data_use_scopes business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.data_use_scopes FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.data_use_scopes'::regclass, 'insert'::text, to_jsonb(data_use_scopes.*)));



-- Name: material_lineage_manifests business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.material_lineage_manifests FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.material_lineage_manifests'::regclass, 'insert'::text, to_jsonb(material_lineage_manifests.*)));



-- Name: material_use_dependencies business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.material_use_dependencies FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.material_use_dependencies'::regclass, 'insert'::text, to_jsonb(material_use_dependencies.*)));



-- Name: migration_cohort_control business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.migration_cohort_control FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.migration_cohort_control'::regclass, 'insert'::text, to_jsonb(migration_cohort_control.*)));



-- Name: migration_cohort_members business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.migration_cohort_members FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.migration_cohort_members'::regclass, 'insert'::text, to_jsonb(migration_cohort_members.*)));



-- Name: object_purpose_business_context_refs business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.object_purpose_business_context_refs FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.object_purpose_business_context_refs'::regclass, 'insert'::text, to_jsonb(object_purpose_business_context_refs.*)));



-- Name: resource_right_fences business_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_insert ON public.resource_right_fences FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.resource_right_fences'::regclass, 'insert'::text, to_jsonb(resource_right_fences.*)));



-- Name: authority_qualification_receipts business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.authority_qualification_receipts FOR SELECT TO builderad_scope_executor USING (true);



-- Name: boundary_subjects business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.boundary_subjects FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_authority_fences business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_authority_fences FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_branches business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_branches FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_command_receipts business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_command_receipts FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_context_heads business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_context_heads FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_context_transitions business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_context_transitions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_current_intents business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_current_intents FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_data_use_bindings business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_data_use_bindings FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_declared_markets business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_declared_markets FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_goal_preferences business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_goal_preferences FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_intent_transitions business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_intent_transitions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_market_transitions business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_market_transitions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_preference_transitions business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.business_preference_transitions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: businesses business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.businesses FOR SELECT TO builderad_scope_executor USING (true);



-- Name: client_data_boundaries business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.client_data_boundaries FOR SELECT TO builderad_scope_executor USING (true);



-- Name: data_use_scopes business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.data_use_scopes FOR SELECT TO builderad_scope_executor USING (true);



-- Name: material_lineage_manifests business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.material_lineage_manifests FOR SELECT TO builderad_scope_executor USING (true);



-- Name: material_use_dependencies business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.material_use_dependencies FOR SELECT TO builderad_scope_executor USING (true);



-- Name: migration_cohort_control business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.migration_cohort_control FOR SELECT TO builderad_scope_executor USING (true);



-- Name: migration_cohort_members business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.migration_cohort_members FOR SELECT TO builderad_scope_executor USING (true);



-- Name: object_purpose_business_context_refs business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.object_purpose_business_context_refs FOR SELECT TO builderad_scope_executor USING (true);



-- Name: resource_right_fences business_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_read ON public.resource_right_fences FOR SELECT TO builderad_scope_executor USING (true);



-- Name: authority_qualification_receipts business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.authority_qualification_receipts FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.authority_qualification_receipts'::regclass, 'update'::text, to_jsonb(authority_qualification_receipts.*)));



-- Name: boundary_subjects business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.boundary_subjects FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.boundary_subjects'::regclass, 'update'::text, to_jsonb(boundary_subjects.*)));



-- Name: business_authority_fences business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.business_authority_fences FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.business_authority_fences'::regclass, 'update'::text, to_jsonb(business_authority_fences.*)));



-- Name: business_branches business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.business_branches FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.business_branches'::regclass, 'update'::text, to_jsonb(business_branches.*)));



-- Name: business_context_heads business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.business_context_heads FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.business_context_heads'::regclass, 'update'::text, to_jsonb(business_context_heads.*)));



-- Name: business_current_intents business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.business_current_intents FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.business_current_intents'::regclass, 'update'::text, to_jsonb(business_current_intents.*)));



-- Name: business_data_use_bindings business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.business_data_use_bindings FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.business_data_use_bindings'::regclass, 'update'::text, to_jsonb(business_data_use_bindings.*)));



-- Name: business_declared_markets business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.business_declared_markets FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.business_declared_markets'::regclass, 'update'::text, to_jsonb(business_declared_markets.*)));



-- Name: business_goal_preferences business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.business_goal_preferences FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.business_goal_preferences'::regclass, 'update'::text, to_jsonb(business_goal_preferences.*)));



-- Name: businesses business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.businesses FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.businesses'::regclass, 'update'::text, to_jsonb(businesses.*)));



-- Name: client_data_boundaries business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.client_data_boundaries FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.client_data_boundaries'::regclass, 'update'::text, to_jsonb(client_data_boundaries.*)));



-- Name: data_use_scopes business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.data_use_scopes FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.data_use_scopes'::regclass, 'update'::text, to_jsonb(data_use_scopes.*)));



-- Name: migration_cohort_control business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.migration_cohort_control FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.migration_cohort_control'::regclass, 'update'::text, to_jsonb(migration_cohort_control.*)));



-- Name: migration_cohort_members business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.migration_cohort_members FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.migration_cohort_members'::regclass, 'update'::text, to_jsonb(migration_cohort_members.*)));



-- Name: resource_right_fences business_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_executor_update ON public.resource_right_fences FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.resource_right_fences'::regclass, 'update'::text, to_jsonb(resource_right_fences.*)));



-- Name: business_expenditure_summaries; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_expenditure_summaries ENABLE ROW LEVEL SECURITY;


-- Name: market_business_finding_captures business_finding_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_finding_insert ON public.market_business_finding_captures FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.market_business_finding_captures'::regclass, 'insert'::text, to_jsonb(market_business_finding_captures.*)));



-- Name: market_business_finding_captures business_finding_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_finding_owner ON public.market_business_finding_captures TO builderad_owner USING (true) WITH CHECK (true);



-- Name: market_business_finding_captures business_finding_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_finding_read ON public.market_business_finding_captures FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_business_finding_captures business_finding_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_finding_update ON public.market_business_finding_captures FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.market_business_finding_captures'::regclass, 'update'::text, to_jsonb(market_business_finding_captures.*)));



-- Name: business_goal_preferences; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_goal_preferences ENABLE ROW LEVEL SECURITY;


-- Name: business_source_guidance business_guidance_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_guidance_owner ON public.business_source_guidance TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_source_guidance business_guidance_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_guidance_read ON public.business_source_guidance FOR SELECT TO builderad_app, builderad_authority USING (true);



-- Name: business_intent_transitions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_intent_transitions ENABLE ROW LEVEL SECURITY;


-- Name: business_declared_markets business_market_delete; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_market_delete ON public.business_declared_markets FOR DELETE TO builderad_scope_executor USING (true);



-- Name: business_market_transitions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_market_transitions ENABLE ROW LEVEL SECURITY;


-- Name: authority_qualification_receipts business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.authority_qualification_receipts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: boundary_subjects business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.boundary_subjects TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_authority_fences business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_authority_fences TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_branches business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_branches TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_command_receipts business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_command_receipts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_context_heads business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_context_heads TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_context_transitions business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_context_transitions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_current_intents business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_current_intents TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_data_use_bindings business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_data_use_bindings TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_declared_markets business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_declared_markets TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_goal_preferences business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_goal_preferences TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_intent_transitions business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_intent_transitions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_market_transitions business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_market_transitions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_preference_transitions business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.business_preference_transitions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: businesses business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.businesses TO builderad_owner USING (true) WITH CHECK (true);



-- Name: client_data_boundaries business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.client_data_boundaries TO builderad_owner USING (true) WITH CHECK (true);



-- Name: data_use_scopes business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.data_use_scopes TO builderad_owner USING (true) WITH CHECK (true);



-- Name: material_lineage_manifests business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.material_lineage_manifests TO builderad_owner USING (true) WITH CHECK (true);



-- Name: material_use_dependencies business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.material_use_dependencies TO builderad_owner USING (true) WITH CHECK (true);



-- Name: migration_cohort_control business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.migration_cohort_control TO builderad_owner USING (true) WITH CHECK (true);



-- Name: migration_cohort_members business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.migration_cohort_members TO builderad_owner USING (true) WITH CHECK (true);



-- Name: object_purpose_business_context_refs business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.object_purpose_business_context_refs TO builderad_owner USING (true) WITH CHECK (true);



-- Name: resource_right_fences business_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_owner ON public.resource_right_fences TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_practice_assessments; Type: ROW SECURITY; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE public.business_practice_assessments ENABLE ROW LEVEL SECURITY;


-- Name: business_practice_revisions; Type: ROW SECURITY; Schema: public; Owner: builderad_scope_executor
--

ALTER TABLE public.business_practice_revisions ENABLE ROW LEVEL SECURITY;


-- Name: business_preference_transitions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_preference_transitions ENABLE ROW LEVEL SECURITY;


-- Name: business_promotion_priors business_priors_internal; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_priors_internal ON public.business_promotion_priors FOR SELECT TO builderad_authority USING (true);



-- Name: business_promotion_priors business_priors_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_priors_owner ON public.business_promotion_priors TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_promotion_priors business_priors_public; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_priors_public ON public.business_promotion_priors FOR SELECT TO builderad_app USING ((approved AND active));



-- Name: business_promotion_priors; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_promotion_priors ENABLE ROW LEVEL SECURITY;


-- Name: security_source_audit_custody business_source_custody_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_source_custody_read ON public.security_source_audit_custody FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_source_guidance; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_source_guidance ENABLE ROW LEVEL SECURITY;


-- Name: business_types; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_types ENABLE ROW LEVEL SECURITY;


-- Name: business_types business_types_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_types_owner ON public.business_types TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_types business_types_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY business_types_read ON public.business_types FOR SELECT TO builderad_app, builderad_authority USING (true);



-- Name: businesses; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.businesses ENABLE ROW LEVEL SECURITY;


-- Name: commercial_catalog_references catalog_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY catalog_read ON public.commercial_catalog_references FOR SELECT TO builderad_app USING ((public.builderad_sa01_has('ReadCommercialMarketRegistry'::text, market_id) OR (EXISTS ( SELECT 1
   FROM public.commercial_market_assignments a
  WHERE (a.catalog_reference_id = commercial_catalog_references.id)))));



-- Name: client_data_boundaries; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.client_data_boundaries ENABLE ROW LEVEL SECURITY;


-- Name: commercial_audit_delivery; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_audit_delivery ENABLE ROW LEVEL SECURITY;


-- Name: commercial_audit_obligations; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_audit_obligations ENABLE ROW LEVEL SECURITY;


-- Name: commercial_beneficiary_bindings; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_beneficiary_bindings ENABLE ROW LEVEL SECURITY;


-- Name: commercial_catalog_references; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_catalog_references ENABLE ROW LEVEL SECURITY;


-- Name: commercial_command_results; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_command_results ENABLE ROW LEVEL SECURITY;


-- Name: commercial_founder_market_notes; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_founder_market_notes ENABLE ROW LEVEL SECURITY;


-- Name: commercial_market_assignability; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_market_assignability ENABLE ROW LEVEL SECURITY;


-- Name: commercial_market_assignments; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_market_assignments ENABLE ROW LEVEL SECURITY;


-- Name: commercial_market_configuration_versions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_market_configuration_versions ENABLE ROW LEVEL SECURITY;


-- Name: commercial_market_countries; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_market_countries ENABLE ROW LEVEL SECURITY;


-- Name: commercial_markets; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_markets ENABLE ROW LEVEL SECURITY;


-- Name: commercial_scopes; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.commercial_scopes ENABLE ROW LEVEL SECURITY;


-- Name: market_processing_completion_tickets completion_ticket_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY completion_ticket_executor_read ON public.market_processing_completion_tickets FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_processing_completion_tickets completion_ticket_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY completion_ticket_executor_update ON public.market_processing_completion_tickets FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.market_processing_completion_tickets'::regclass, 'update'::text, to_jsonb(market_processing_completion_tickets.*)));



-- Name: market_processing_completion_tickets completion_ticket_expiry_cleanup; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY completion_ticket_expiry_cleanup ON public.market_processing_completion_tickets FOR DELETE TO builderad_authority USING ((expires_at <= clock_timestamp()));



-- Name: market_processing_completion_tickets completion_ticket_expiry_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY completion_ticket_expiry_read ON public.market_processing_completion_tickets FOR SELECT TO builderad_authority USING (true);



-- Name: market_processing_completion_tickets completion_ticket_issuer; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY completion_ticket_issuer ON public.market_processing_completion_tickets FOR INSERT TO builderad_authority WITH CHECK ((consumed_at IS NULL));



-- Name: market_processing_completion_tickets completion_ticket_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY completion_ticket_owner ON public.market_processing_completion_tickets TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_market_configuration_versions config_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY config_read ON public.commercial_market_configuration_versions FOR SELECT TO builderad_app USING ((public.builderad_sa01_has('ReadCommercialMarketRegistry'::text, market_id) OR (EXISTS ( SELECT 1
   FROM public.commercial_market_assignments a
  WHERE (a.configuration_id = commercial_market_configuration_versions.id)))));



-- Name: commercial_market_countries country_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY country_owner ON public.commercial_market_countries TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_market_countries country_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY country_read ON public.commercial_market_countries FOR SELECT TO builderad_app USING ((public.builderad_sa01_has('ReadCommercialMarketRegistry'::text, market_id) OR (EXISTS ( SELECT 1
   FROM public.commercial_market_assignments a
  WHERE (a.market_id = commercial_market_countries.market_id)))));



-- Name: security_commercial_audit_custody custody_authority; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY custody_authority ON public.security_commercial_audit_custody TO builderad_authority USING (true) WITH CHECK (true);



-- Name: customer_administration_events; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.customer_administration_events ENABLE ROW LEVEL SECURITY;


-- Name: customer_administration_events customer_events_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY customer_events_owner ON public.customer_administration_events TO builderad_owner USING (true) WITH CHECK (true);



-- Name: data_use_scopes; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.data_use_scopes ENABLE ROW LEVEL SECURITY;


-- Name: commercial_audit_delivery delivery_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY delivery_read ON public.commercial_audit_delivery FOR SELECT TO builderad_app USING ((EXISTS ( SELECT 1
   FROM public.commercial_audit_obligations o
  WHERE (o.id = commercial_audit_delivery.obligation_id))));



-- Name: business_expenditure_summaries expenditure_summary_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY expenditure_summary_insert ON public.business_expenditure_summaries FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_expenditure_summaries'::regclass, 'insert'::text, to_jsonb(business_expenditure_summaries.*)));



-- Name: business_expenditure_summaries expenditure_summary_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY expenditure_summary_owner ON public.business_expenditure_summaries TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_expenditure_summaries expenditure_summary_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY expenditure_summary_read ON public.business_expenditure_summaries FOR SELECT TO builderad_scope_executor USING (true);



-- Name: business_expenditure_summaries expenditure_summary_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY expenditure_summary_update ON public.business_expenditure_summaries FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.business_expenditure_summaries'::regclass, 'update'::text, to_jsonb(business_expenditure_summaries.*)));



-- Name: commercial_founder_market_notes founder_notes_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY founder_notes_owner ON public.commercial_founder_market_notes TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_founder_market_notes founder_notes_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY founder_notes_read ON public.commercial_founder_market_notes FOR SELECT TO builderad_app USING (public.builderad_sa01_founder());



-- Name: google_read_attempts google_attempt_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY google_attempt_owner ON public.google_read_attempts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: google_read_attempts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.google_read_attempts ENABLE ROW LEVEL SECURITY;


-- Name: security_action_grants grant_app_closed; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY grant_app_closed ON public.security_action_grants AS RESTRICTIVE TO builderad_app USING (((scope_kind)::text <> ALL ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying])::text[]))) WITH CHECK (((scope_kind)::text <> ALL ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying])::text[])));



-- Name: security_action_grants grant_authority_closed; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY grant_authority_closed ON public.security_action_grants AS RESTRICTIVE TO builderad_authority USING (((scope_kind)::text <> ALL ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying])::text[]))) WITH CHECK (((scope_kind)::text <> ALL ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying])::text[])));



-- Name: security_action_grants grant_authority_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY grant_authority_insert ON public.security_action_grants FOR INSERT TO builderad_authority WITH CHECK (((scope_kind)::text <> ALL ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying])::text[])));



-- Name: security_action_grants grant_authority_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY grant_authority_read ON public.security_action_grants FOR SELECT TO builderad_authority USING (((scope_kind)::text <> ALL ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying])::text[])));



-- Name: security_action_grants grant_authority_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY grant_authority_update ON public.security_action_grants FOR UPDATE TO builderad_authority USING (((scope_kind)::text <> ALL ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying])::text[]))) WITH CHECK (((scope_kind)::text <> ALL ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying])::text[])));



-- Name: security_action_grants grant_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY grant_executor_read ON public.security_action_grants FOR SELECT TO builderad_scope_executor USING (true);



-- Name: security_action_grants grant_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY grant_executor_update ON public.security_action_grants FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK ((((scope_kind)::text = ANY ((ARRAY['business'::character varying, 'client_data'::character varying, 'data_use'::character varying, 'source'::character varying])::text[])) AND builderad_private.effect_allowed('public.security_action_grants'::regclass, 'update'::text, to_jsonb(security_action_grants.*))));



-- Name: security_action_grants grant_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY grant_owner ON public.security_action_grants TO builderad_owner USING (true) WITH CHECK (true);



-- Name: inquiry_evidence_enrollments; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.inquiry_evidence_enrollments ENABLE ROW LEVEL SECURITY;


-- Name: inquiry_evidence_heads; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.inquiry_evidence_heads ENABLE ROW LEVEL SECURITY;


-- Name: inquiry_evidence_revisions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.inquiry_evidence_revisions ENABLE ROW LEVEL SECURITY;


-- Name: inquiry_evidence_enrollments inquiry_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_executor_insert ON public.inquiry_evidence_enrollments FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.inquiry_evidence_enrollments'::regclass, 'insert'::text, to_jsonb(inquiry_evidence_enrollments.*)));



-- Name: inquiry_evidence_heads inquiry_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_executor_insert ON public.inquiry_evidence_heads FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.inquiry_evidence_heads'::regclass, 'insert'::text, to_jsonb(inquiry_evidence_heads.*)));



-- Name: inquiry_evidence_revisions inquiry_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_executor_insert ON public.inquiry_evidence_revisions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.inquiry_evidence_revisions'::regclass, 'insert'::text, to_jsonb(inquiry_evidence_revisions.*)));



-- Name: inquiry_evidence_enrollments inquiry_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_executor_read ON public.inquiry_evidence_enrollments FOR SELECT TO builderad_scope_executor USING (true);



-- Name: inquiry_evidence_heads inquiry_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_executor_read ON public.inquiry_evidence_heads FOR SELECT TO builderad_scope_executor USING (true);



-- Name: inquiry_evidence_revisions inquiry_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_executor_read ON public.inquiry_evidence_revisions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: inquiry_evidence_enrollments inquiry_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_executor_update ON public.inquiry_evidence_enrollments FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.inquiry_evidence_enrollments'::regclass, 'update'::text, to_jsonb(inquiry_evidence_enrollments.*)));



-- Name: inquiry_evidence_heads inquiry_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_executor_update ON public.inquiry_evidence_heads FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.inquiry_evidence_heads'::regclass, 'update'::text, to_jsonb(inquiry_evidence_heads.*)));



-- Name: inquiry_evidence_enrollments inquiry_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_owner ON public.inquiry_evidence_enrollments TO builderad_owner USING (true) WITH CHECK (true);



-- Name: inquiry_evidence_heads inquiry_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_owner ON public.inquiry_evidence_heads TO builderad_owner USING (true) WITH CHECK (true);



-- Name: inquiry_evidence_revisions inquiry_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY inquiry_owner ON public.inquiry_evidence_revisions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: market_knowledge_captures knowledge_capture_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_capture_insert ON public.market_knowledge_captures FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.market_knowledge_captures'::regclass, 'insert'::text, to_jsonb(market_knowledge_captures.*)));



-- Name: market_knowledge_captures knowledge_capture_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_capture_owner ON public.market_knowledge_captures TO builderad_owner USING (true) WITH CHECK (true);



-- Name: market_knowledge_captures knowledge_capture_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_capture_read ON public.market_knowledge_captures FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_knowledge_captures knowledge_capture_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_capture_update ON public.market_knowledge_captures FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.market_knowledge_captures'::regclass, 'update'::text, to_jsonb(market_knowledge_captures.*)));



-- Name: market_knowledge_completion_tickets knowledge_ticket_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_ticket_executor_read ON public.market_knowledge_completion_tickets FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_knowledge_completion_tickets knowledge_ticket_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_ticket_executor_update ON public.market_knowledge_completion_tickets FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.market_knowledge_completion_tickets'::regclass, 'update'::text, to_jsonb(market_knowledge_completion_tickets.*)));



-- Name: market_knowledge_completion_tickets knowledge_ticket_expiry_cleanup; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_ticket_expiry_cleanup ON public.market_knowledge_completion_tickets FOR DELETE TO builderad_authority USING ((expires_at <= clock_timestamp()));



-- Name: market_knowledge_completion_tickets knowledge_ticket_expiry_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_ticket_expiry_read ON public.market_knowledge_completion_tickets FOR SELECT TO builderad_authority USING (true);



-- Name: market_knowledge_completion_tickets knowledge_ticket_issuer; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_ticket_issuer ON public.market_knowledge_completion_tickets FOR INSERT TO builderad_authority WITH CHECK ((consumed_at IS NULL));



-- Name: market_knowledge_completion_tickets knowledge_ticket_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY knowledge_ticket_owner ON public.market_knowledge_completion_tickets TO builderad_owner USING (true) WITH CHECK (true);



-- Name: security_commercial_mandate_executions mandate_authority; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY mandate_authority ON public.security_commercial_mandate_executions TO builderad_authority USING (true) WITH CHECK (true);



-- Name: security_commercial_market_approvals market_approval_authority; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_approval_authority ON public.security_commercial_market_approvals TO builderad_authority USING (true) WITH CHECK (true);



-- Name: security_commercial_market_approvals market_approval_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_approval_owner ON public.security_commercial_market_approvals TO builderad_owner USING (true) WITH CHECK (true);



-- Name: market_business_finding_captures; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_business_finding_captures ENABLE ROW LEVEL SECURITY;


-- Name: market_evidence_heads; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_evidence_heads ENABLE ROW LEVEL SECURITY;


-- Name: market_evidence_revisions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_evidence_revisions ENABLE ROW LEVEL SECURITY;


-- Name: market_evidence_transitions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_evidence_transitions ENABLE ROW LEVEL SECURITY;


-- Name: market_evidence_heads market_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_insert ON public.market_evidence_heads FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.market_evidence_heads'::regclass, 'insert'::text, to_jsonb(market_evidence_heads.*)));



-- Name: market_evidence_revisions market_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_insert ON public.market_evidence_revisions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.market_evidence_revisions'::regclass, 'insert'::text, to_jsonb(market_evidence_revisions.*)));



-- Name: market_evidence_transitions market_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_insert ON public.market_evidence_transitions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.market_evidence_transitions'::regclass, 'insert'::text, to_jsonb(market_evidence_transitions.*)));



-- Name: market_source_custody market_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_insert ON public.market_source_custody FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.market_source_custody'::regclass, 'insert'::text, to_jsonb(market_source_custody.*)));



-- Name: market_evidence_heads market_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_read ON public.market_evidence_heads FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_evidence_revisions market_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_read ON public.market_evidence_revisions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_evidence_transitions market_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_read ON public.market_evidence_transitions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_source_custody market_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_read ON public.market_source_custody FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_evidence_heads market_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_update ON public.market_evidence_heads FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.market_evidence_heads'::regclass, 'update'::text, to_jsonb(market_evidence_heads.*)));



-- Name: market_source_custody market_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_executor_update ON public.market_source_custody FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.market_source_custody'::regclass, 'update'::text, to_jsonb(market_source_custody.*)));



-- Name: market_knowledge_captures; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_knowledge_captures ENABLE ROW LEVEL SECURITY;


-- Name: market_knowledge_completion_tickets; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_knowledge_completion_tickets ENABLE ROW LEVEL SECURITY;


-- Name: market_evidence_heads market_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_owner ON public.market_evidence_heads TO builderad_owner USING (true) WITH CHECK (true);



-- Name: market_evidence_revisions market_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_owner ON public.market_evidence_revisions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: market_evidence_transitions market_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_owner ON public.market_evidence_transitions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: market_source_custody market_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_owner ON public.market_source_custody TO builderad_owner USING (true) WITH CHECK (true);



-- Name: market_processing_completion_tickets; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_processing_completion_tickets ENABLE ROW LEVEL SECURITY;


-- Name: market_processing_runs; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_processing_runs ENABLE ROW LEVEL SECURITY;


-- Name: commercial_markets market_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY market_read ON public.commercial_markets FOR SELECT TO builderad_app USING ((public.builderad_sa01_has('ReadCommercialMarketRegistry'::text, id) OR (EXISTS ( SELECT 1
   FROM public.commercial_market_assignments a
  WHERE (a.market_id = commercial_markets.id)))));



-- Name: market_source_custody; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.market_source_custody ENABLE ROW LEVEL SECURITY;


-- Name: material_lineage_manifests; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.material_lineage_manifests ENABLE ROW LEVEL SECURITY;


-- Name: material_use_dependencies; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.material_use_dependencies ENABLE ROW LEVEL SECURITY;


-- Name: meta_read_attempts meta_attempt_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY meta_attempt_owner ON public.meta_read_attempts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: meta_read_attempts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.meta_read_attempts ENABLE ROW LEVEL SECURITY;


-- Name: migration_cohort_control; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.migration_cohort_control ENABLE ROW LEVEL SECURITY;


-- Name: migration_cohort_members; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.migration_cohort_members ENABLE ROW LEVEL SECURITY;


-- Name: object_purpose_business_context_refs; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.object_purpose_business_context_refs ENABLE ROW LEVEL SECURITY;


-- Name: object_purpose_command_receipts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.object_purpose_command_receipts ENABLE ROW LEVEL SECURITY;


-- Name: object_purpose_command_receipts object_purpose_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY object_purpose_owner ON public.object_purpose_command_receipts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: object_purpose_revisions object_purpose_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY object_purpose_owner ON public.object_purpose_revisions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: object_purposes object_purpose_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY object_purpose_owner ON public.object_purposes TO builderad_owner USING (true) WITH CHECK (true);



-- Name: object_purpose_revisions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.object_purpose_revisions ENABLE ROW LEVEL SECURITY;


-- Name: object_purposes; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.object_purposes ENABLE ROW LEVEL SECURITY;


-- Name: commercial_audit_obligations obligation_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY obligation_read ON public.commercial_audit_obligations FOR SELECT TO builderad_app USING ((public.builderad_sa01_has('ReadCommercialAuditDeliveryState'::text, target_id, business_id) OR public.builderad_sa01_has('RetryOrRecoverExistingAuditDelivery'::text, target_id, business_id)));



-- Name: provider_observation_entries observation_entry_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY observation_entry_read ON public.provider_observation_entries FOR SELECT TO builderad_app USING ((EXISTS ( SELECT 1
   FROM public.provider_observation_streams s
  WHERE (s.namespace = provider_observation_entries.namespace))));



-- Name: provider_observation_entries observation_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY observation_owner ON public.provider_observation_entries TO builderad_owner USING (true) WITH CHECK (true);



-- Name: provider_observation_runs observation_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY observation_owner ON public.provider_observation_runs TO builderad_owner USING (true) WITH CHECK (true);



-- Name: provider_observation_streams observation_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY observation_owner ON public.provider_observation_streams TO builderad_owner USING (true) WITH CHECK (true);



-- Name: provider_observation_runs observation_run_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY observation_run_read ON public.provider_observation_runs FOR SELECT TO builderad_app USING ((EXISTS ( SELECT 1
   FROM public.provider_observation_streams s
  WHERE (s.namespace = provider_observation_runs.namespace))));



-- Name: business_practice_revisions practice_insert; Type: POLICY; Schema: public; Owner: builderad_scope_executor
--

CREATE POLICY practice_insert ON public.business_practice_revisions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.business_practice_revisions'::regclass, 'insert'::text, to_jsonb(business_practice_revisions.*)));



-- Name: business_practice_revisions practice_owner; Type: POLICY; Schema: public; Owner: builderad_scope_executor
--

CREATE POLICY practice_owner ON public.business_practice_revisions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_practice_revisions practice_read; Type: POLICY; Schema: public; Owner: builderad_scope_executor
--

CREATE POLICY practice_read ON public.business_practice_revisions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_processing_runs processing_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY processing_executor_insert ON public.market_processing_runs FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.market_processing_runs'::regclass, 'insert'::text, to_jsonb(market_processing_runs.*)));



-- Name: market_processing_runs processing_executor_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY processing_executor_read ON public.market_processing_runs FOR SELECT TO builderad_scope_executor USING (true);



-- Name: market_processing_runs processing_executor_update; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY processing_executor_update ON public.market_processing_runs FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.market_processing_runs'::regclass, 'update'::text, to_jsonb(market_processing_runs.*)));



-- Name: market_processing_runs processing_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY processing_owner ON public.market_processing_runs TO builderad_owner USING (true) WITH CHECK (true);



-- Name: provider_access; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.provider_access ENABLE ROW LEVEL SECURITY;


-- Name: provider_access_events; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.provider_access_events ENABLE ROW LEVEL SECURITY;


-- Name: provider_access_receipts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.provider_access_receipts ENABLE ROW LEVEL SECURITY;


-- Name: provider_access_events provider_event_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY provider_event_read ON public.provider_access_events FOR SELECT TO builderad_app USING ((EXISTS ( SELECT 1
   FROM public.provider_access_receipts r
  WHERE ((r.namespace = provider_access_events.namespace) AND (r.command_id = provider_access_events.command_id)))));



-- Name: provider_observation_entries; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.provider_observation_entries ENABLE ROW LEVEL SECURITY;


-- Name: provider_observation_runs; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.provider_observation_runs ENABLE ROW LEVEL SECURITY;


-- Name: provider_observation_streams; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.provider_observation_streams ENABLE ROW LEVEL SECURITY;


-- Name: provider_access provider_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY provider_owner ON public.provider_access TO builderad_owner USING (true) WITH CHECK (true);



-- Name: provider_access_events provider_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY provider_owner ON public.provider_access_events TO builderad_owner USING (true) WITH CHECK (true);



-- Name: provider_access_receipts provider_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY provider_owner ON public.provider_access_receipts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: object_purpose_command_receipts purpose_scope_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY purpose_scope_insert ON public.object_purpose_command_receipts FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.object_purpose_command_receipts'::regclass, 'insert'::text, to_jsonb(object_purpose_command_receipts.*)));



-- Name: object_purpose_revisions purpose_scope_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY purpose_scope_insert ON public.object_purpose_revisions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.object_purpose_revisions'::regclass, 'insert'::text, to_jsonb(object_purpose_revisions.*)));



-- Name: object_purposes purpose_scope_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY purpose_scope_insert ON public.object_purposes FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.object_purposes'::regclass, 'insert'::text, to_jsonb(object_purposes.*)));



-- Name: object_purposes purpose_scope_lock; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY purpose_scope_lock ON public.object_purposes FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (builderad_private.effect_allowed('public.object_purposes'::regclass, 'update'::text, to_jsonb(object_purposes.*)));



-- Name: object_purpose_command_receipts purpose_scope_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY purpose_scope_read ON public.object_purpose_command_receipts FOR SELECT TO builderad_scope_executor USING (true);



-- Name: object_purpose_revisions purpose_scope_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY purpose_scope_read ON public.object_purpose_revisions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: object_purposes purpose_scope_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY purpose_scope_read ON public.object_purposes FOR SELECT TO builderad_scope_executor USING (true);



-- Name: commercial_command_results receipt_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY receipt_read ON public.commercial_command_results FOR SELECT TO builderad_app USING (public.builderad_sa01_has('ReadCommercialRegistrationReceipt'::text, target_id, business_id));



-- Name: resource_right_fences; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.resource_right_fences ENABLE ROW LEVEL SECURITY;


-- Name: commercial_audit_delivery sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_audit_delivery TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_audit_obligations sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_audit_obligations TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_beneficiary_bindings sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_beneficiary_bindings TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_catalog_references sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_catalog_references TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_command_results sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_command_results TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_market_assignability sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_market_assignability TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_market_assignments sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_market_assignments TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_market_configuration_versions sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_market_configuration_versions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_markets sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_markets TO builderad_owner USING (true) WITH CHECK (true);



-- Name: commercial_scopes sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.commercial_scopes TO builderad_owner USING (true) WITH CHECK (true);



-- Name: security_commercial_audit_custody sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.security_commercial_audit_custody TO builderad_owner USING (true) WITH CHECK (true);



-- Name: security_commercial_mandate_executions sa01_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY sa01_owner ON public.security_commercial_mandate_executions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: business_types scope_metadata_lock; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_metadata_lock ON public.business_types FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (false);



-- Name: principal_lifecycles scope_metadata_lock; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_metadata_lock ON public.principal_lifecycles FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (false);



-- Name: security_sessions scope_metadata_lock; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_metadata_lock ON public.security_sessions FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (false);



-- Name: users scope_metadata_lock; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_metadata_lock ON public.users FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (false);



-- Name: business_types scope_metadata_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_metadata_read ON public.business_types FOR SELECT TO builderad_scope_executor USING (true);



-- Name: principal_lifecycles scope_metadata_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_metadata_read ON public.principal_lifecycles FOR SELECT TO builderad_scope_executor USING (true);



-- Name: security_sessions scope_metadata_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_metadata_read ON public.security_sessions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: users scope_metadata_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_metadata_read ON public.users FOR SELECT TO builderad_scope_executor USING (true);



-- Name: source_audit_delivery scope_native_append; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_native_append ON public.source_audit_delivery FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.source_audit_delivery'::regclass, 'insert'::text, to_jsonb(source_audit_delivery.*)));



-- Name: source_audit_obligations scope_native_append; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_native_append ON public.source_audit_obligations FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.source_audit_obligations'::regclass, 'insert'::text, to_jsonb(source_audit_obligations.*)));



-- Name: source_command_receipts scope_native_append; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_native_append ON public.source_command_receipts FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.source_command_receipts'::regclass, 'insert'::text, to_jsonb(source_command_receipts.*)));



-- Name: source_versions scope_native_append; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_native_append ON public.source_versions FOR INSERT TO builderad_scope_executor WITH CHECK (builderad_private.effect_allowed('public.source_versions'::regclass, 'insert'::text, to_jsonb(source_versions.*)));



-- Name: source_audit_delivery scope_native_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_native_read ON public.source_audit_delivery FOR SELECT TO builderad_scope_executor USING (true);



-- Name: source_audit_obligations scope_native_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_native_read ON public.source_audit_obligations FOR SELECT TO builderad_scope_executor USING (true);



-- Name: source_command_receipts scope_native_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_native_read ON public.source_command_receipts FOR SELECT TO builderad_scope_executor USING (true);



-- Name: source_versions scope_native_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_native_read ON public.source_versions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: provider_observation_entries scope_observation_entry_metadata; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_observation_entry_metadata ON public.provider_observation_entries FOR SELECT TO builderad_scope_executor USING (true);



-- Name: provider_observation_runs scope_observation_run_metadata; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_observation_run_metadata ON public.provider_observation_runs FOR SELECT TO builderad_scope_executor USING (true);



-- Name: provider_observation_streams scope_observation_stream_metadata; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_observation_stream_metadata ON public.provider_observation_streams FOR SELECT TO builderad_scope_executor USING (true);



-- Name: object_purpose_revisions scope_qualified_history; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_qualified_history ON public.object_purpose_revisions FOR SELECT TO builderad_scope_executor USING (true);



-- Name: commercial_scopes scope_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY scope_read ON public.commercial_scopes FOR SELECT TO builderad_app USING ((EXISTS ( SELECT 1
   FROM public.commercial_beneficiary_bindings b
  WHERE (b.scope_id = commercial_scopes.id))));



-- Name: security_action_grants; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.security_action_grants ENABLE ROW LEVEL SECURITY;


-- Name: security_commercial_audit_custody; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.security_commercial_audit_custody ENABLE ROW LEVEL SECURITY;


-- Name: security_commercial_mandate_executions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.security_commercial_mandate_executions ENABLE ROW LEVEL SECURITY;


-- Name: security_commercial_market_approvals; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.security_commercial_market_approvals ENABLE ROW LEVEL SECURITY;


-- Name: security_source_audit_custody; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.security_source_audit_custody ENABLE ROW LEVEL SECURITY;


-- Name: snapchat_read_attempts snapchat_attempt_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY snapchat_attempt_owner ON public.snapchat_read_attempts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: snapchat_read_attempts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.snapchat_read_attempts ENABLE ROW LEVEL SECURITY;


-- Name: snapchat_renewal_failures; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.snapchat_renewal_failures ENABLE ROW LEVEL SECURITY;


-- Name: snapchat_renewal_failures snapchat_renewal_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY snapchat_renewal_owner ON public.snapchat_renewal_failures TO builderad_owner USING (true) WITH CHECK (true);



-- Name: source_audit_delivery; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.source_audit_delivery ENABLE ROW LEVEL SECURITY;


-- Name: source_audit_obligations; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.source_audit_obligations ENABLE ROW LEVEL SECURITY;


-- Name: source_capabilities; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.source_capabilities ENABLE ROW LEVEL SECURITY;


-- Name: source_capabilities source_capability_executor_insert; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_capability_executor_insert ON public.source_capabilities FOR INSERT TO builderad_scope_executor WITH CHECK ((((EXISTS ( SELECT 1
   FROM public.resource_right_fences f
  WHERE ((f.source_id = source_capabilities.source_id) AND (f.kind = 'source'::text) AND (f.adoption_state = 'v2_adopted'::text)))) OR (EXISTS ( SELECT 1
   FROM public.source_registry s
  WHERE ((s.id = source_capabilities.source_id) AND (s.custody_kind = 'business_native'::text))))) AND builderad_private.effect_allowed('public.source_capabilities'::regclass, 'insert'::text, to_jsonb(source_capabilities.*))));



-- Name: source_capabilities source_capability_executor_metadata_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_capability_executor_metadata_read ON public.source_capabilities FOR SELECT TO builderad_scope_executor USING (true);



-- Name: source_command_receipts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.source_command_receipts ENABLE ROW LEVEL SECURITY;


-- Name: security_source_audit_custody source_custody_authority; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_custody_authority ON public.security_source_audit_custody TO builderad_authority USING (true) WITH CHECK (true);



-- Name: source_registry source_executor_metadata_read; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_executor_metadata_read ON public.source_registry FOR SELECT TO builderad_scope_executor USING (true);



-- Name: source_registry source_executor_write; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_executor_write ON public.source_registry FOR UPDATE TO builderad_scope_executor USING (true) WITH CHECK (((EXISTS ( SELECT 1
   FROM public.resource_right_fences
  WHERE ((resource_right_fences.source_id = source_registry.id) AND (resource_right_fences.kind = 'source'::text) AND (resource_right_fences.adoption_state = 'v2_adopted'::text)))) AND builderad_private.effect_allowed('public.source_registry'::regclass, 'update'::text, to_jsonb(source_registry.*))));



-- Name: security_source_audit_custody source_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_owner ON public.security_source_audit_custody TO builderad_owner USING (true) WITH CHECK (true);



-- Name: source_audit_delivery source_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_owner ON public.source_audit_delivery TO builderad_owner USING (true) WITH CHECK (true);



-- Name: source_audit_obligations source_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_owner ON public.source_audit_obligations TO builderad_owner USING (true) WITH CHECK (true);



-- Name: source_capabilities source_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_owner ON public.source_capabilities TO builderad_owner USING (true) WITH CHECK (true);



-- Name: source_command_receipts source_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_owner ON public.source_command_receipts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: source_registry source_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_owner ON public.source_registry TO builderad_owner USING (true) WITH CHECK (true);



-- Name: source_versions source_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY source_owner ON public.source_versions TO builderad_owner USING (true) WITH CHECK (true);



-- Name: source_registry; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.source_registry ENABLE ROW LEVEL SECURITY;


-- Name: source_versions; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.source_versions ENABLE ROW LEVEL SECURITY;


-- Name: tiktok_read_attempts tiktok_attempt_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY tiktok_attempt_owner ON public.tiktok_read_attempts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: tiktok_read_attempts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.tiktok_read_attempts ENABLE ROW LEVEL SECURITY;


-- Name: x_read_attempts x_attempt_owner; Type: POLICY; Schema: public; Owner: builderad_owner
--

CREATE POLICY x_attempt_owner ON public.x_read_attempts TO builderad_owner USING (true) WITH CHECK (true);



-- Name: x_read_attempts; Type: ROW SECURITY; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.x_read_attempts ENABLE ROW LEVEL SECURITY;


-- Name: SCHEMA builderad_private; Type: ACL; Schema: -; Owner: builderad_owner
--

GRANT USAGE ON SCHEMA builderad_private TO builderad_scope_executor;



-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: builderad_owner
--

GRANT USAGE ON SCHEMA public TO builderad_app;
GRANT USAGE ON SCHEMA public TO builderad_authority;
GRANT USAGE ON SCHEMA public TO builderad_scope_executor;



-- Name: TABLE market_knowledge_captures; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.market_knowledge_captures TO builderad_scope_executor;



-- Name: TABLE market_knowledge_completion_tickets; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT INSERT,DELETE ON TABLE public.market_knowledge_completion_tickets TO builderad_authority;
GRANT SELECT,UPDATE ON TABLE public.market_knowledge_completion_tickets TO builderad_scope_executor;



-- Name: COLUMN market_knowledge_completion_tickets.expires_at; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(expires_at) ON TABLE public.market_knowledge_completion_tickets TO builderad_authority;



-- Name: TABLE data_use_scopes; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.data_use_scopes TO builderad_scope_executor;



-- Name: TABLE business_practice_revisions; Type: ACL; Schema: public; Owner: builderad_scope_executor
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_practice_revisions TO builderad_scope_executor;



-- Name: TABLE market_processing_completion_tickets; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT INSERT,DELETE ON TABLE public.market_processing_completion_tickets TO builderad_authority;
GRANT SELECT,UPDATE ON TABLE public.market_processing_completion_tickets TO builderad_scope_executor;



-- Name: COLUMN market_processing_completion_tickets.expires_at; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(expires_at) ON TABLE public.market_processing_completion_tickets TO builderad_authority;



-- Name: TABLE market_processing_runs; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.market_processing_runs TO builderad_scope_executor;



-- Name: TABLE command_permits; Type: ACL; Schema: builderad_private; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE builderad_private.command_permits TO builderad_scope_executor;



-- Name: TABLE invocations; Type: ACL; Schema: builderad_private; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE builderad_private.invocations TO builderad_scope_executor;



-- Name: TABLE authority_qualification_receipts; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.authority_qualification_receipts TO builderad_scope_executor;



-- Name: TABLE boundary_subjects; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.boundary_subjects TO builderad_scope_executor;



-- Name: TABLE browser_identity_transitions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.browser_identity_transitions TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.browser_identity_transitions TO builderad_authority;



-- Name: TABLE builderad_context_tickets; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT INSERT,DELETE,UPDATE ON TABLE public.builderad_context_tickets TO builderad_authority;



-- Name: COLUMN builderad_context_tickets.expires_at; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(expires_at) ON TABLE public.builderad_context_tickets TO builderad_authority;



-- Name: TABLE business_authority_fences; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_authority_fences TO builderad_scope_executor;



-- Name: TABLE business_branches; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_branches TO builderad_scope_executor;



-- Name: SEQUENCE business_branches_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT USAGE ON SEQUENCE public.business_branches_id_seq TO builderad_scope_executor;



-- Name: TABLE business_command_receipts; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.business_command_receipts TO builderad_scope_executor;



-- Name: TABLE business_context_heads; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_context_heads TO builderad_scope_executor;



-- Name: TABLE business_context_transitions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.business_context_transitions TO builderad_scope_executor;



-- Name: TABLE business_current_intents; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_current_intents TO builderad_scope_executor;



-- Name: TABLE business_data_use_bindings; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_data_use_bindings TO builderad_scope_executor;



-- Name: TABLE business_declared_markets; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.business_declared_markets TO builderad_scope_executor;



-- Name: TABLE business_expenditure_summaries; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_expenditure_summaries TO builderad_scope_executor;



-- Name: TABLE business_goal_preferences; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_goal_preferences TO builderad_scope_executor;



-- Name: TABLE business_intent_transitions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.business_intent_transitions TO builderad_scope_executor;



-- Name: TABLE business_market_transitions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.business_market_transitions TO builderad_scope_executor;



-- Name: TABLE business_practice_assessments; Type: ACL; Schema: public; Owner: builderad_scope_executor
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.business_practice_assessments TO builderad_scope_executor;



-- Name: TABLE business_preference_transitions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.business_preference_transitions TO builderad_scope_executor;



-- Name: TABLE business_promotion_priors; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.business_promotion_priors TO builderad_authority;



-- Name: COLUMN business_promotion_priors.id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(id) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.business_type_id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(business_type_id) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.provider; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(provider) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.product; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(product) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.basis; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(basis) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.applicability; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(applicability) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.limits; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(limits) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.rationale_ar; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(rationale_ar) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.rationale_en; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(rationale_en) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.approved; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(approved) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.active; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(active) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: COLUMN business_promotion_priors.version; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(version) ON TABLE public.business_promotion_priors TO builderad_app;



-- Name: TABLE business_source_guidance; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.business_source_guidance TO builderad_app;
GRANT SELECT ON TABLE public.business_source_guidance TO builderad_authority;



-- Name: TABLE business_types; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.business_types TO builderad_app;
GRANT SELECT ON TABLE public.business_types TO builderad_authority;
GRANT SELECT ON TABLE public.business_types TO builderad_scope_executor;



-- Name: COLUMN business_types.scope_lock_marker; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT UPDATE(scope_lock_marker) ON TABLE public.business_types TO builderad_scope_executor;



-- Name: TABLE businesses; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.businesses TO builderad_scope_executor;



-- Name: SEQUENCE businesses_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT USAGE ON SEQUENCE public.businesses_id_seq TO builderad_scope_executor;



-- Name: TABLE client_data_boundaries; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.client_data_boundaries TO builderad_scope_executor;



-- Name: TABLE commercial_audit_delivery; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_audit_delivery TO builderad_app;



-- Name: TABLE commercial_audit_obligations; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_audit_obligations TO builderad_app;



-- Name: TABLE commercial_beneficiary_bindings; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_beneficiary_bindings TO builderad_app;



-- Name: TABLE commercial_catalog_references; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_catalog_references TO builderad_app;



-- Name: TABLE commercial_command_results; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_command_results TO builderad_app;



-- Name: TABLE commercial_founder_market_notes; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_founder_market_notes TO builderad_app;



-- Name: TABLE commercial_market_assignability; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_market_assignability TO builderad_app;



-- Name: TABLE commercial_market_assignments; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_market_assignments TO builderad_app;



-- Name: TABLE commercial_market_configuration_versions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_market_configuration_versions TO builderad_app;



-- Name: TABLE commercial_market_countries; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_market_countries TO builderad_app;



-- Name: TABLE commercial_markets; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_markets TO builderad_app;



-- Name: TABLE commercial_scopes; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.commercial_scopes TO builderad_app;



-- Name: TABLE content_article_revisions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.content_article_revisions TO builderad_app;



-- Name: SEQUENCE content_article_revisions_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.content_article_revisions_id_seq TO builderad_app;



-- Name: TABLE content_articles; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.content_articles TO builderad_app;



-- Name: SEQUENCE content_articles_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.content_articles_id_seq TO builderad_app;



-- Name: TABLE content_categories; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.content_categories TO builderad_app;



-- Name: SEQUENCE content_categories_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.content_categories_id_seq TO builderad_app;



-- Name: TABLE content_footer_settings; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.content_footer_settings TO builderad_app;



-- Name: TABLE content_navigation_links; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.content_navigation_links TO builderad_app;



-- Name: SEQUENCE content_navigation_links_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.content_navigation_links_id_seq TO builderad_app;



-- Name: TABLE content_navigation_sections; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.content_navigation_sections TO builderad_app;



-- Name: SEQUENCE content_navigation_sections_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.content_navigation_sections_id_seq TO builderad_app;



-- Name: TABLE content_page_revisions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.content_page_revisions TO builderad_app;



-- Name: SEQUENCE content_page_revisions_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.content_page_revisions_id_seq TO builderad_app;



-- Name: TABLE content_pages; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.content_pages TO builderad_app;



-- Name: SEQUENCE content_pages_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.content_pages_id_seq TO builderad_app;



-- Name: TABLE country_references; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.country_references TO builderad_app;
GRANT SELECT ON TABLE public.country_references TO builderad_scope_executor;



-- Name: TABLE current_intent_timezone_catalog; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.current_intent_timezone_catalog TO builderad_app;
GRANT SELECT ON TABLE public.current_intent_timezone_catalog TO builderad_scope_executor;



-- Name: TABLE failed_jobs; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.failed_jobs TO builderad_app;



-- Name: COLUMN failed_jobs.id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(id) ON TABLE public.failed_jobs TO builderad_scope_executor;



-- Name: SEQUENCE failed_jobs_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.failed_jobs_id_seq TO builderad_app;



-- Name: TABLE global_appearance; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.global_appearance TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.global_appearance TO builderad_authority;



-- Name: TABLE inquiry_evidence_enrollments; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,TRIGGER,UPDATE ON TABLE public.inquiry_evidence_enrollments TO builderad_scope_executor;



-- Name: TABLE inquiry_evidence_heads; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,TRIGGER,UPDATE ON TABLE public.inquiry_evidence_heads TO builderad_scope_executor;



-- Name: TABLE inquiry_evidence_revisions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,TRIGGER ON TABLE public.inquiry_evidence_revisions TO builderad_scope_executor;



-- Name: TABLE internal_personnel; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.internal_personnel TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.internal_personnel TO builderad_authority;



-- Name: COLUMN internal_personnel.runtime_lock_marker; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT UPDATE(runtime_lock_marker) ON TABLE public.internal_personnel TO builderad_app;



-- Name: TABLE jobs; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.jobs TO builderad_app;



-- Name: COLUMN jobs.id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(id) ON TABLE public.jobs TO builderad_scope_executor;



-- Name: SEQUENCE jobs_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,USAGE ON SEQUENCE public.jobs_id_seq TO builderad_app;



-- Name: TABLE market_business_finding_captures; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.market_business_finding_captures TO builderad_scope_executor;



-- Name: TABLE market_evidence_heads; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.market_evidence_heads TO builderad_scope_executor;



-- Name: TABLE market_evidence_revisions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.market_evidence_revisions TO builderad_scope_executor;



-- Name: TABLE market_evidence_transitions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.market_evidence_transitions TO builderad_scope_executor;



-- Name: TABLE market_source_custody; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.market_source_custody TO builderad_scope_executor;



-- Name: TABLE material_lineage_manifests; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.material_lineage_manifests TO builderad_scope_executor;



-- Name: TABLE material_use_dependencies; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.material_use_dependencies TO builderad_scope_executor;



-- Name: TABLE migration_cohort_control; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.migration_cohort_control TO builderad_scope_executor;



-- Name: TABLE migration_cohort_members; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.migration_cohort_members TO builderad_scope_executor;



-- Name: TABLE object_purpose_business_context_refs; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.object_purpose_business_context_refs TO builderad_scope_executor;



-- Name: TABLE object_purpose_command_receipts; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.object_purpose_command_receipts TO builderad_app;
GRANT SELECT,INSERT ON TABLE public.object_purpose_command_receipts TO builderad_scope_executor;



-- Name: TABLE object_purpose_revisions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.object_purpose_revisions TO builderad_app;
GRANT SELECT,INSERT ON TABLE public.object_purpose_revisions TO builderad_scope_executor;



-- Name: TABLE object_purposes; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.object_purposes TO builderad_app;
GRANT SELECT,INSERT ON TABLE public.object_purposes TO builderad_scope_executor;



-- Name: COLUMN object_purposes.head_version; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT UPDATE(head_version) ON TABLE public.object_purposes TO builderad_scope_executor;



-- Name: TABLE passkeys; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.passkeys TO builderad_app;
GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.passkeys TO builderad_authority;



-- Name: SEQUENCE passkeys_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON SEQUENCE public.passkeys_id_seq TO builderad_app;
GRANT SELECT,USAGE ON SEQUENCE public.passkeys_id_seq TO builderad_authority;



-- Name: TABLE password_reset_tokens; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.password_reset_tokens TO builderad_app;
GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.password_reset_tokens TO builderad_authority;



-- Name: TABLE platform_maintenance; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.platform_maintenance TO builderad_app;
GRANT SELECT,UPDATE ON TABLE public.platform_maintenance TO builderad_authority;



-- Name: TABLE platform_maintenance_commands; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.platform_maintenance_commands TO builderad_authority;



-- Name: TABLE principal_lifecycles; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.principal_lifecycles TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.principal_lifecycles TO builderad_authority;
GRANT SELECT ON TABLE public.principal_lifecycles TO builderad_scope_executor;



-- Name: COLUMN principal_lifecycles.runtime_lock_marker; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT UPDATE(runtime_lock_marker) ON TABLE public.principal_lifecycles TO builderad_app;
GRANT UPDATE(runtime_lock_marker) ON TABLE public.principal_lifecycles TO builderad_scope_executor;



-- Name: COLUMN provider_access.source_id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(source_id) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.provider; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(provider) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.resource; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(resource) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.operation; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(operation) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.purpose; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(purpose) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.version; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(version) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.local_state; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(local_state) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.native_state; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(native_state) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.credential_expires_at; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(credential_expires_at) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.verification; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(verification) ON TABLE public.provider_access TO builderad_app;



-- Name: COLUMN provider_access.recorded_at; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(recorded_at) ON TABLE public.provider_access TO builderad_app;



-- Name: TABLE provider_access_events; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.provider_access_events TO builderad_app;



-- Name: TABLE provider_access_receipts; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.provider_access_receipts TO builderad_app;



-- Name: TABLE provider_observation_entries; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.provider_observation_entries TO builderad_app;



-- Name: COLUMN provider_observation_entries.namespace; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(namespace) ON TABLE public.provider_observation_entries TO builderad_scope_executor;



-- Name: COLUMN provider_observation_entries.generation; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(generation) ON TABLE public.provider_observation_entries TO builderad_scope_executor;



-- Name: COLUMN provider_observation_entries.identity; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(identity) ON TABLE public.provider_observation_entries TO builderad_scope_executor;



-- Name: COLUMN provider_observation_entries.content_hash; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(content_hash) ON TABLE public.provider_observation_entries TO builderad_scope_executor;



-- Name: COLUMN provider_observation_entries.observation; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(observation) ON TABLE public.provider_observation_entries TO builderad_scope_executor;



-- Name: TABLE provider_observation_runs; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.provider_observation_runs TO builderad_app;



-- Name: COLUMN provider_observation_runs.namespace; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(namespace) ON TABLE public.provider_observation_runs TO builderad_scope_executor;



-- Name: COLUMN provider_observation_runs.generation; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(generation) ON TABLE public.provider_observation_runs TO builderad_scope_executor;



-- Name: COLUMN provider_observation_runs.command_id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(command_id) ON TABLE public.provider_observation_runs TO builderad_scope_executor;



-- Name: COLUMN provider_observation_runs.coverage; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(coverage) ON TABLE public.provider_observation_runs TO builderad_scope_executor;



-- Name: COLUMN provider_observation_runs.provenance; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(provenance) ON TABLE public.provider_observation_runs TO builderad_scope_executor;



-- Name: COLUMN provider_observation_runs.recorded_at; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(recorded_at) ON TABLE public.provider_observation_runs TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.namespace; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(namespace) ON TABLE public.provider_observation_streams TO builderad_app;
GRANT SELECT(namespace) ON TABLE public.provider_observation_streams TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.source_id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(source_id) ON TABLE public.provider_observation_streams TO builderad_app;
GRANT SELECT(source_id) ON TABLE public.provider_observation_streams TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.provider; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(provider) ON TABLE public.provider_observation_streams TO builderad_app;
GRANT SELECT(provider) ON TABLE public.provider_observation_streams TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.resource; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(resource) ON TABLE public.provider_observation_streams TO builderad_app;
GRANT SELECT(resource) ON TABLE public.provider_observation_streams TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.operation; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(operation) ON TABLE public.provider_observation_streams TO builderad_app;
GRANT SELECT(operation) ON TABLE public.provider_observation_streams TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.purpose; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(purpose) ON TABLE public.provider_observation_streams TO builderad_app;
GRANT SELECT(purpose) ON TABLE public.provider_observation_streams TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.stream; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(stream) ON TABLE public.provider_observation_streams TO builderad_app;
GRANT SELECT(stream) ON TABLE public.provider_observation_streams TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.generation; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(generation) ON TABLE public.provider_observation_streams TO builderad_app;
GRANT SELECT(generation) ON TABLE public.provider_observation_streams TO builderad_scope_executor;



-- Name: COLUMN provider_observation_streams.access_version; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(access_version) ON TABLE public.provider_observation_streams TO builderad_app;



-- Name: COLUMN provider_observation_streams.progress_generation; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(progress_generation) ON TABLE public.provider_observation_streams TO builderad_app;



-- Name: TABLE registration_email_proofs; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.registration_email_proofs TO builderad_authority;



-- Name: TABLE resource_right_fences; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.resource_right_fences TO builderad_scope_executor;



-- Name: TABLE security_action_grants; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.security_action_grants TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.security_action_grants TO builderad_authority;
GRANT SELECT,INSERT,UPDATE ON TABLE public.security_action_grants TO builderad_scope_executor;



-- Name: COLUMN security_action_grants.runtime_lock_marker; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT UPDATE(runtime_lock_marker) ON TABLE public.security_action_grants TO builderad_app;
GRANT UPDATE(runtime_lock_marker) ON TABLE public.security_action_grants TO builderad_scope_executor;



-- Name: SEQUENCE security_action_grants_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON SEQUENCE public.security_action_grants_id_seq TO builderad_app;
GRANT SELECT,USAGE ON SEQUENCE public.security_action_grants_id_seq TO builderad_authority;
GRANT USAGE ON SEQUENCE public.security_action_grants_id_seq TO builderad_scope_executor;



-- Name: TABLE security_audit_events; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.security_audit_events TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.security_audit_events TO builderad_authority;
GRANT INSERT ON TABLE public.security_audit_events TO builderad_scope_executor;



-- Name: SEQUENCE security_audit_events_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON SEQUENCE public.security_audit_events_id_seq TO builderad_app;
GRANT SELECT,USAGE ON SEQUENCE public.security_audit_events_id_seq TO builderad_authority;
GRANT USAGE ON SEQUENCE public.security_audit_events_id_seq TO builderad_scope_executor;



-- Name: TABLE security_commercial_audit_custody; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.security_commercial_audit_custody TO builderad_authority;



-- Name: TABLE security_commercial_mandate_executions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.security_commercial_mandate_executions TO builderad_authority;



-- Name: TABLE security_commercial_market_approvals; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.security_commercial_market_approvals TO builderad_authority;



-- Name: TABLE security_sessions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.security_sessions TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.security_sessions TO builderad_authority;
GRANT SELECT ON TABLE public.security_sessions TO builderad_scope_executor;



-- Name: COLUMN security_sessions.runtime_lock_marker; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT UPDATE(runtime_lock_marker) ON TABLE public.security_sessions TO builderad_app;
GRANT UPDATE(runtime_lock_marker) ON TABLE public.security_sessions TO builderad_scope_executor;



-- Name: SEQUENCE security_sessions_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON SEQUENCE public.security_sessions_id_seq TO builderad_app;
GRANT SELECT,USAGE ON SEQUENCE public.security_sessions_id_seq TO builderad_authority;



-- Name: TABLE security_source_audit_custody; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT,INSERT ON TABLE public.security_source_audit_custody TO builderad_authority;
GRANT SELECT ON TABLE public.security_source_audit_custody TO builderad_scope_executor;



-- Name: TABLE service_action_grants; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.service_action_grants TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.service_action_grants TO builderad_authority;



-- Name: SEQUENCE service_action_grants_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON SEQUENCE public.service_action_grants_id_seq TO builderad_app;
GRANT SELECT,USAGE ON SEQUENCE public.service_action_grants_id_seq TO builderad_authority;



-- Name: TABLE service_audit_events; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.service_audit_events TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.service_audit_events TO builderad_authority;



-- Name: SEQUENCE service_audit_events_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON SEQUENCE public.service_audit_events_id_seq TO builderad_app;
GRANT SELECT,USAGE ON SEQUENCE public.service_audit_events_id_seq TO builderad_authority;



-- Name: TABLE service_identities; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.service_identities TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.service_identities TO builderad_authority;



-- Name: COLUMN service_identities.runtime_lock_marker; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT UPDATE(runtime_lock_marker) ON TABLE public.service_identities TO builderad_app;



-- Name: SEQUENCE service_identities_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON SEQUENCE public.service_identities_id_seq TO builderad_app;
GRANT SELECT,USAGE ON SEQUENCE public.service_identities_id_seq TO builderad_authority;



-- Name: TABLE service_business_scopes; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.service_business_scopes TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.service_business_scopes TO builderad_authority;



-- Name: TABLE snapchat_renewal_failures; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.snapchat_renewal_failures TO builderad_app;



-- Name: TABLE source_audit_delivery; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.source_audit_delivery TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.source_audit_delivery TO builderad_scope_executor;



-- Name: TABLE source_audit_obligations; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.source_audit_obligations TO builderad_app;
GRANT SELECT,INSERT ON TABLE public.source_audit_obligations TO builderad_scope_executor;



-- Name: TABLE source_capabilities; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.source_capabilities TO builderad_app;
GRANT SELECT,INSERT ON TABLE public.source_capabilities TO builderad_scope_executor;



-- Name: TABLE source_command_receipts; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.source_command_receipts TO builderad_app;
GRANT SELECT,INSERT ON TABLE public.source_command_receipts TO builderad_scope_executor;



-- Name: TABLE source_registry; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.source_registry TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.source_registry TO builderad_scope_executor;



-- Name: TABLE source_versions; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.source_versions TO builderad_app;
GRANT SELECT,INSERT ON TABLE public.source_versions TO builderad_scope_executor;



-- Name: TABLE users; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON TABLE public.users TO builderad_app;
GRANT SELECT,INSERT,UPDATE ON TABLE public.users TO builderad_authority;



-- Name: COLUMN users.id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(id) ON TABLE public.users TO builderad_scope_executor;



-- Name: COLUMN users.public_id; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(public_id) ON TABLE public.users TO builderad_scope_executor;



-- Name: COLUMN users.name; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(name) ON TABLE public.users TO builderad_scope_executor;



-- Name: COLUMN users.email; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(email) ON TABLE public.users TO builderad_scope_executor;



-- Name: COLUMN users.email_verified_at; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(email_verified_at) ON TABLE public.users TO builderad_scope_executor;



-- Name: COLUMN users.scope_lock_marker; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT(scope_lock_marker),UPDATE(scope_lock_marker) ON TABLE public.users TO builderad_scope_executor;



-- Name: SEQUENCE users_id_seq; Type: ACL; Schema: public; Owner: builderad_owner
--

GRANT SELECT ON SEQUENCE public.users_id_seq TO builderad_app;
GRANT SELECT,USAGE ON SEQUENCE public.users_id_seq TO builderad_authority;


ALTER TABLE public.security_action_grants ADD CONSTRAINT security_action_grants_commercial_effect_check CHECK (
    scope_kind <> 'commercial' OR (
        uuid_extract_version(commercial_target_id) = 7 AND (
            (action IN ('ReadCommercialMarketRegistry','ConfigureCommercialMarket','EnableCommercialMarket','DisableCommercialMarket') AND business_id IS NULL AND commercial_version_id IS NULL)
            OR (action = 'RecordOrVerifyApprovedMarketReference' AND business_id IS NULL AND commercial_version_id IS NOT NULL)
            OR (action IN ('RegisterCommercialScope','RecordBeneficiaryBinding','ReadCommercialContextAdvertiser','ReadCommercialContextInternal') AND business_id IS NOT NULL AND commercial_version_id IS NULL)
            OR (action IN ('ReadCommercialRegistrationReceipt','ReadCommercialAuditDeliveryState','RetryOrRecoverExistingAuditDelivery') AND commercial_version_id IS NULL)
            OR (action = 'AssignCommercialMarket' AND business_id IS NOT NULL AND commercial_version_id IS NOT NULL)
            OR action = 'SA01CommercialGrantAdministrator'
        )
    )
);
