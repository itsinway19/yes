-- Name: market_knowledge_captures; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_knowledge_captures (
    run_id uuid NOT NULL,
    actor_user_id bigint NOT NULL,
    generation bigint NOT NULL,
    session_digest bytea NOT NULL,
    question text NOT NULL,
    method_ref text NOT NULL,
    purpose text NOT NULL,
    material_refs jsonb NOT NULL,
    input_digest bytea NOT NULL,
    input_reads jsonb NOT NULL,
    state text DEFAULT 'prepared'::text NOT NULL,
    finding jsonb,
    continuation_rights jsonb,
    completion_attestation_digest bytea,
    output_scope_id uuid,
    publication_manifest_id uuid,
    material_digest bytea,
    created_at timestamp with time zone NOT NULL,
    completed_at timestamp with time zone,
    CONSTRAINT market_knowledge_captures_check CHECK (((state = 'prepared'::text) = (completed_at IS NULL))),
    CONSTRAINT market_knowledge_captures_check1 CHECK (((state = ANY (ARRAY['published'::text, 'retention_removed'::text])) = (publication_manifest_id IS NOT NULL))),
    CONSTRAINT market_knowledge_captures_check2 CHECK (((state <> 'published'::text) OR ((finding IS NOT NULL) AND (completion_attestation_digest IS NOT NULL) AND (output_scope_id IS NOT NULL) AND (material_digest IS NOT NULL)))),
    CONSTRAINT market_knowledge_captures_completion_attestation_digest_check CHECK (((completion_attestation_digest IS NULL) OR (octet_length(completion_attestation_digest) = 32))),
    CONSTRAINT market_knowledge_captures_input_digest_check CHECK ((octet_length(input_digest) = 32)),
    CONSTRAINT market_knowledge_captures_material_digest_check CHECK (((material_digest IS NULL) OR (octet_length(material_digest) = 32))),
    CONSTRAINT market_knowledge_captures_material_refs_check CHECK (((jsonb_typeof(material_refs) = 'array'::text) AND ((jsonb_array_length(material_refs) >= 1) AND (jsonb_array_length(material_refs) <= 2)))),
    CONSTRAINT market_knowledge_captures_method_ref_check CHECK ((method_ref = ANY (ARRAY['offer_equivalence:1'::text, 'qualified_interest_change:1'::text, 'qualified_price_observation:1'::text, 'qualified_geography_observation:1'::text]))),
    CONSTRAINT market_knowledge_captures_purpose_check CHECK (((length(purpose) >= 1) AND (length(purpose) <= 128))),
    CONSTRAINT market_knowledge_captures_question_check CHECK ((question = ANY (ARRAY['compare_offers'::text, 'compare_interest'::text, 'record_price'::text, 'record_geography'::text]))),
    CONSTRAINT market_knowledge_captures_session_digest_check CHECK ((octet_length(session_digest) = 32)),
    CONSTRAINT market_knowledge_captures_state_check CHECK ((state = ANY (ARRAY['prepared'::text, 'validated'::text, 'abstained'::text, 'failed'::text, 'published'::text, 'retention_removed'::text])))
);

ALTER TABLE ONLY public.market_knowledge_captures FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_knowledge_captures OWNER TO builderad_owner;


-- Name: market_knowledge_completion_tickets; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_knowledge_completion_tickets (
    token_hash text NOT NULL,
    run_id uuid NOT NULL,
    command_id uuid NOT NULL,
    actor_user_id bigint NOT NULL,
    generation bigint NOT NULL,
    method_ref text NOT NULL,
    session_digest bytea NOT NULL,
    binding_digest bytea NOT NULL,
    backend_pid integer NOT NULL,
    transaction_id xid8 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    consumed_at timestamp with time zone,
    CONSTRAINT market_knowledge_completion_tickets_binding_digest_check CHECK ((octet_length(binding_digest) = 32)),
    CONSTRAINT market_knowledge_completion_tickets_check CHECK ((isfinite(created_at) AND isfinite(expires_at) AND (expires_at > created_at) AND (expires_at <= (created_at + '00:00:30'::interval)))),
    CONSTRAINT market_knowledge_completion_tickets_check1 CHECK (((consumed_at IS NULL) OR (isfinite(consumed_at) AND (consumed_at >= created_at)))),
    CONSTRAINT market_knowledge_completion_tickets_session_digest_check CHECK ((octet_length(session_digest) = 32)),
    CONSTRAINT market_knowledge_completion_tickets_token_hash_check CHECK ((token_hash ~ '^[0-9a-f]{64}$'::text))
);

ALTER TABLE ONLY public.market_knowledge_completion_tickets FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_knowledge_completion_tickets OWNER TO builderad_owner;


-- Name: data_use_scopes; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.data_use_scopes (
    scope_id uuid NOT NULL,
    boundary_id uuid,
    source_id uuid NOT NULL,
    resource text NOT NULL,
    selection jsonb NOT NULL,
    selection_schema_version smallint NOT NULL,
    operation text NOT NULL,
    purpose text NOT NULL,
    scope_digest bytea NOT NULL,
    status text NOT NULL,
    revision bigint NOT NULL,
    rights_domain text DEFAULT 'client'::text NOT NULL,
    CONSTRAINT data_use_domain_shape CHECK ((((rights_domain = 'client'::text) AND (boundary_id IS NOT NULL) AND (operation <> ALL (ARRAY['ingest'::text, 'correct'::text]))) OR ((rights_domain = 'market'::text) AND (boundary_id IS NULL)))),
    CONSTRAINT data_use_scopes_operation_check CHECK ((operation = ANY (ARRAY['retained_read'::text, 'process'::text, 'combine'::text, 'display'::text, 'reuse'::text, 'export'::text, 'train'::text, 'retain'::text, 'delete'::text, 'forward'::text, 'ingest'::text, 'correct'::text]))),
    CONSTRAINT data_use_scopes_purpose_check CHECK (((length(purpose) >= 1) AND (length(purpose) <= 128))),
    CONSTRAINT data_use_scopes_resource_check CHECK (((length(resource) >= 1) AND (length(resource) <= 180))),
    CONSTRAINT data_use_scopes_revision_check CHECK ((revision > 0)),
    CONSTRAINT data_use_scopes_rights_domain_check CHECK ((rights_domain = ANY (ARRAY['client'::text, 'market'::text]))),
    CONSTRAINT data_use_scopes_scope_digest_check CHECK ((octet_length(scope_digest) = 32)),
    CONSTRAINT data_use_scopes_scope_id_check CHECK ((uuid_extract_version(scope_id) = 7)),
    CONSTRAINT data_use_scopes_selection_schema_version_check CHECK ((selection_schema_version = 1)),
    CONSTRAINT data_use_scopes_status_check CHECK ((status = ANY (ARRAY['active'::text, 'retired'::text]))),
    CONSTRAINT scope_canonical_identity CHECK (builderad_private.market_scope_identity(rights_domain, boundary_id, source_id, resource, operation, purpose, selection, scope_digest))
);

ALTER TABLE ONLY public.data_use_scopes FORCE ROW LEVEL SECURITY;


ALTER TABLE public.data_use_scopes OWNER TO builderad_owner;


-- Name: business_practice_revisions; Type: TABLE; Schema: public; Owner: builderad_scope_executor
--

CREATE TABLE public.business_practice_revisions (
    business_id bigint NOT NULL,
    practice_id uuid NOT NULL,
    revision bigint NOT NULL,
    operation text NOT NULL,
    corrects_revision bigint,
    correction_reason text,
    state text NOT NULL,
    value jsonb NOT NULL,
    actor_id bigint NOT NULL,
    command_id uuid NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    CONSTRAINT business_practice_revisions_check CHECK ((((operation = 'correct'::text) AND (corrects_revision IS NOT NULL) AND (correction_reason IS NOT NULL)) OR ((operation <> 'correct'::text) AND (corrects_revision IS NULL) AND (correction_reason IS NULL)))),
    CONSTRAINT business_practice_revisions_check1 CHECK (((corrects_revision IS NULL) OR (corrects_revision = (revision - 1)))),
    CONSTRAINT business_practice_revisions_correction_reason_check CHECK (((correction_reason IS NULL) OR ((length(btrim(correction_reason)) >= 3) AND (length(btrim(correction_reason)) <= 1000)))),
    CONSTRAINT business_practice_revisions_operation_check CHECK ((operation = ANY (ARRAY['create'::text, 'update'::text, 'correct'::text, 'reclassify'::text, 'deactivate'::text]))),
    CONSTRAINT business_practice_revisions_practice_id_check CHECK ((NOT (uuid_extract_version(practice_id) IS DISTINCT FROM 7))),
    CONSTRAINT business_practice_revisions_revision_check CHECK ((revision > 0)),
    CONSTRAINT business_practice_revisions_state_check CHECK ((state = ANY (ARRAY['active'::text, 'inactive'::text])))
);

ALTER TABLE ONLY public.business_practice_revisions FORCE ROW LEVEL SECURITY;




-- Name: market_processing_completion_tickets; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_processing_completion_tickets (
    token_hash text NOT NULL,
    run_id uuid NOT NULL,
    command_id uuid NOT NULL,
    actor_user_id bigint NOT NULL,
    generation bigint NOT NULL,
    business_public_id uuid CONSTRAINT market_processing_completion_ticket_business_public_id_not_null NOT NULL,
    method_ref text NOT NULL,
    session_digest bytea NOT NULL,
    binding_digest bytea NOT NULL,
    backend_pid integer NOT NULL,
    transaction_id xid8 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    consumed_at timestamp with time zone,
    CONSTRAINT market_processing_completion_tickets_binding_digest_check CHECK ((octet_length(binding_digest) = 32)),
    CONSTRAINT market_processing_completion_tickets_check CHECK ((isfinite(created_at) AND isfinite(expires_at) AND (expires_at > created_at) AND (expires_at <= (created_at + '00:00:30'::interval)))),
    CONSTRAINT market_processing_completion_tickets_check1 CHECK (((consumed_at IS NULL) OR (isfinite(consumed_at) AND (consumed_at >= created_at)))),
    CONSTRAINT market_processing_completion_tickets_session_digest_check CHECK ((octet_length(session_digest) = 32)),
    CONSTRAINT market_processing_completion_tickets_token_hash_check CHECK ((token_hash ~ '^[0-9a-f]{64}$'::text))
);

ALTER TABLE ONLY public.market_processing_completion_tickets FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_processing_completion_tickets OWNER TO builderad_owner;


-- Name: market_processing_runs; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_processing_runs (
    run_id uuid NOT NULL,
    business_id bigint NOT NULL,
    actor_user_id bigint NOT NULL,
    generation bigint NOT NULL,
    session_digest bytea NOT NULL,
    question text NOT NULL,
    method_ref text NOT NULL,
    purpose text NOT NULL,
    material_refs jsonb NOT NULL,
    request_digest bytea NOT NULL,
    selection_digest bytea NOT NULL,
    input_digest bytea NOT NULL,
    dependency_digest bytea NOT NULL,
    state text NOT NULL,
    finding jsonb,
    reason_code text,
    elapsed_microseconds bigint NOT NULL,
    execution_count smallint NOT NULL,
    prepared_at timestamp with time zone NOT NULL,
    completed_at timestamp with time zone,
    completion_attestation_digest bytea,
    CONSTRAINT market_processing_runs_check CHECK (((state = 'prepared'::text) = (completed_at IS NULL))),
    CONSTRAINT market_processing_runs_check1 CHECK (((state = ANY (ARRAY['validated'::text, 'abstained'::text])) = (finding IS NOT NULL))),
    CONSTRAINT market_processing_runs_completion_attestation_digest_check CHECK (((completion_attestation_digest IS NULL) OR (octet_length(completion_attestation_digest) = 32))),
    CONSTRAINT market_processing_runs_dependency_digest_check CHECK ((octet_length(dependency_digest) = 32)),
    CONSTRAINT market_processing_runs_elapsed_microseconds_check CHECK (((elapsed_microseconds >= 0) AND (elapsed_microseconds <= 30000000))),
    CONSTRAINT market_processing_runs_execution_count_check CHECK ((execution_count = ANY (ARRAY[0, 1]))),
    CONSTRAINT market_processing_runs_input_digest_check CHECK ((octet_length(input_digest) = 32)),
    CONSTRAINT market_processing_runs_material_refs_check CHECK (((jsonb_typeof(material_refs) = 'array'::text) AND ((jsonb_array_length(material_refs) >= 1) AND (jsonb_array_length(material_refs) <= 2)))),
    CONSTRAINT market_processing_runs_method_ref_check CHECK ((method_ref = ANY (ARRAY['offer_equivalence:1'::text, 'qualified_interest_change:1'::text, 'business_feasibility:1'::text]))),
    CONSTRAINT market_processing_runs_purpose_check CHECK (((length(purpose) >= 1) AND (length(purpose) <= 128))),
    CONSTRAINT market_processing_runs_question_check CHECK ((question = ANY (ARRAY['compare_offers'::text, 'compare_interest'::text, 'assess_business_feasibility'::text]))),
    CONSTRAINT market_processing_runs_request_digest_check CHECK ((octet_length(request_digest) = 32)),
    CONSTRAINT market_processing_runs_selection_digest_check CHECK ((octet_length(selection_digest) = 32)),
    CONSTRAINT market_processing_runs_session_digest_check CHECK ((octet_length(session_digest) = 32)),
    CONSTRAINT market_processing_runs_state_check CHECK ((state = ANY (ARRAY['prepared'::text, 'validated'::text, 'abstained'::text, 'failed'::text, 'cancelled'::text, 'unavailable'::text])))
);

ALTER TABLE ONLY public.market_processing_runs FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_processing_runs OWNER TO builderad_owner;


-- Name: command_permits; Type: TABLE; Schema: builderad_private; Owner: builderad_owner
--

CREATE TABLE builderad_private.command_permits (
    permit_id uuid NOT NULL,
    backend_pid integer NOT NULL,
    transaction_id xid8 NOT NULL,
    invocation_nonce uuid NOT NULL,
    action text NOT NULL,
    object_schema text NOT NULL,
    object_name text NOT NULL,
    dml_kind text NOT NULL,
    target_tuple jsonb NOT NULL,
    old_digest bytea,
    new_digest bytea,
    uses_left smallint DEFAULT 1 NOT NULL,
    authority_selection_digest bytea NOT NULL,
    consumed_at timestamp with time zone,
    CONSTRAINT command_permits_dml_kind_check CHECK ((dml_kind = ANY (ARRAY['insert'::text, 'update'::text, 'delete'::text]))),
    CONSTRAINT command_permits_uses_left_check CHECK ((uses_left = ANY (ARRAY[0, 1])))
);

ALTER TABLE ONLY builderad_private.command_permits FORCE ROW LEVEL SECURITY;


ALTER TABLE builderad_private.command_permits OWNER TO builderad_owner;


-- Name: invocations; Type: TABLE; Schema: builderad_private; Owner: builderad_owner
--

CREATE TABLE builderad_private.invocations (
    backend_pid integer NOT NULL,
    transaction_id xid8 NOT NULL,
    nonce uuid NOT NULL,
    command_id uuid NOT NULL,
    actor_user_id bigint,
    generation bigint NOT NULL,
    session_ref_digest bytea,
    action text NOT NULL,
    target_tuple jsonb NOT NULL,
    authority_selection jsonb NOT NULL,
    authority_selection_digest bytea NOT NULL,
    dependency_digest bytea NOT NULL,
    phase text NOT NULL,
    CONSTRAINT invocations_phase_check CHECK ((phase = ANY (ARRAY['authorized'::text, 'completed'::text])))
);

ALTER TABLE ONLY builderad_private.invocations FORCE ROW LEVEL SECURITY;


ALTER TABLE builderad_private.invocations OWNER TO builderad_owner;


-- Name: authority_qualification_receipts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.authority_qualification_receipts (
    qualification_id uuid NOT NULL,
    policy_id text NOT NULL,
    policy_version bigint NOT NULL,
    issuer_kind text NOT NULL,
    issuer_grant_id bigint,
    subject_user_id bigint NOT NULL,
    target_kind text NOT NULL,
    target_ref uuid NOT NULL,
    allowed_actions text[] NOT NULL,
    evidence_ref text NOT NULL,
    evidence_digest bytea NOT NULL,
    status text NOT NULL,
    revision bigint NOT NULL,
    valid_from timestamp with time zone NOT NULL,
    valid_until timestamp with time zone,
    provenance text NOT NULL,
    agency_business_id bigint,
    agency_membership_grant_id bigint,
    CONSTRAINT agency_qualification_shape CHECK ((((agency_business_id IS NULL) AND (agency_membership_grant_id IS NULL) AND (policy_id <> ALL (ARRAY['business_agency_mandate_v1'::text, 'business_agency_assignment_v1'::text]))) OR ((agency_business_id IS NOT NULL) AND (agency_membership_grant_id IS NOT NULL) AND (policy_id = ANY (ARRAY['business_agency_mandate_v1'::text, 'business_agency_assignment_v1'::text])) AND (policy_version = 1) AND (target_kind = 'business'::text) AND (issuer_kind = 'existing_grant'::text) AND (issuer_grant_id IS NOT NULL) AND (valid_until IS NOT NULL)))),
    CONSTRAINT authority_qualification_receipts_check CHECK (((valid_until IS NULL) OR (valid_until > valid_from))),
    CONSTRAINT authority_qualification_receipts_evidence_digest_check CHECK ((octet_length(evidence_digest) = 32)),
    CONSTRAINT authority_qualification_receipts_issuer_kind_check CHECK ((issuer_kind = ANY (ARRAY['self_declared_draft'::text, 'existing_grant'::text, 'qualified_policy'::text]))),
    CONSTRAINT authority_qualification_receipts_policy_version_check CHECK ((policy_version > 0)),
    CONSTRAINT authority_qualification_receipts_qualification_id_check CHECK ((uuid_extract_version(qualification_id) = 7)),
    CONSTRAINT authority_qualification_receipts_revision_check CHECK ((revision > 0)),
    CONSTRAINT authority_qualification_receipts_status_check CHECK ((status = ANY (ARRAY['active'::text, 'revoked'::text])))
);

ALTER TABLE ONLY public.authority_qualification_receipts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.authority_qualification_receipts OWNER TO builderad_owner;


-- Name: boundary_subjects; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.boundary_subjects (
    membership_id uuid NOT NULL,
    boundary_id uuid NOT NULL,
    business_id bigint NOT NULL,
    branch_id bigint,
    status text NOT NULL,
    revision bigint NOT NULL,
    CONSTRAINT boundary_subjects_membership_id_check CHECK ((uuid_extract_version(membership_id) = 7)),
    CONSTRAINT boundary_subjects_revision_check CHECK ((revision > 0)),
    CONSTRAINT boundary_subjects_status_check CHECK ((status = ANY (ARRAY['active'::text, 'ended'::text])))
);

ALTER TABLE ONLY public.boundary_subjects FORCE ROW LEVEL SECURITY;


ALTER TABLE public.boundary_subjects OWNER TO builderad_owner;


-- Name: browser_identity_transitions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.browser_identity_transitions (
    lineage_id uuid NOT NULL,
    boundary character varying(16) NOT NULL,
    epoch bigint DEFAULT 0 NOT NULL,
    current_session_key_hash character varying(64),
    principal_id bigint,
    status character varying(16) DEFAULT 'anonymous'::character varying NOT NULL,
    kind character varying(16) DEFAULT 'bootstrap'::character varying NOT NULL,
    last_nonce character varying(64),
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT browser_identity_transitions_boundary_check CHECK (((boundary)::text = ANY ((ARRAY['advertiser'::character varying, 'internal'::character varying])::text[]))),
    CONSTRAINT browser_identity_transitions_epoch_check CHECK ((epoch >= 0)),
    CONSTRAINT identity_transition_binding CHECK (((((status)::text = 'active'::text) AND (principal_id IS NOT NULL) AND (current_session_key_hash IS NOT NULL) AND ((current_session_key_hash)::text ~ '^[0-9a-f]{64}$'::text)) OR (((status)::text = ANY ((ARRAY['anonymous'::character varying, 'closed'::character varying])::text[])) AND (principal_id IS NULL) AND (current_session_key_hash IS NULL)))),
    CONSTRAINT identity_transition_phase CHECK ((((epoch = 0) AND ((kind)::text = 'bootstrap'::text) AND ((status)::text = 'anonymous'::text) AND (last_nonce IS NULL)) OR ((epoch > 0) AND (last_nonce IS NOT NULL) AND ((last_nonce)::text ~ '^[0-9a-f]{64}$'::text) AND ((((kind)::text = ANY ((ARRAY['login'::character varying, 'rebind'::character varying])::text[])) AND ((status)::text = 'active'::text)) OR (((kind)::text = ANY ((ARRAY['logout'::character varying, 'expired'::character varying])::text[])) AND ((status)::text = 'closed'::text))))))
);


ALTER TABLE public.browser_identity_transitions OWNER TO builderad_owner;


-- Name: builderad_context_secret; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.builderad_context_secret (
    singleton boolean DEFAULT true NOT NULL,
    secret bytea NOT NULL,
    CONSTRAINT builderad_context_secret_secret_check CHECK ((octet_length(secret) = 32)),
    CONSTRAINT builderad_context_secret_singleton_check CHECK (singleton)
);


ALTER TABLE public.builderad_context_secret OWNER TO builderad_owner;


-- Name: builderad_context_tickets; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.builderad_context_tickets (
    token_hash character(64) NOT NULL,
    actor_user_id bigint NOT NULL,
    backend_pid integer NOT NULL,
    transaction_id xid8 NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    consumed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT builderad_context_ticket_lifetime CHECK ((expires_at <= (created_at + '00:00:30'::interval))),
    CONSTRAINT builderad_context_tickets_actor_user_id_check CHECK ((actor_user_id > 0))
);


ALTER TABLE public.builderad_context_tickets OWNER TO builderad_owner;


-- Name: business_authority_fences; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_authority_fences (
    business_id bigint NOT NULL,
    auth_epoch bigint DEFAULT 0 NOT NULL,
    CONSTRAINT business_authority_fences_auth_epoch_check CHECK ((auth_epoch >= 0))
);

ALTER TABLE ONLY public.business_authority_fences FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_authority_fences OWNER TO builderad_owner;


-- Name: business_branches; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_branches (
    id bigint NOT NULL,
    public_id uuid NOT NULL,
    business_id bigint NOT NULL,
    label text NOT NULL,
    status text NOT NULL,
    revision bigint NOT NULL,
    CONSTRAINT business_branches_label_check CHECK (((length(label) >= 1) AND (length(label) <= 120))),
    CONSTRAINT business_branches_public_id_check CHECK ((uuid_extract_version(public_id) = 7)),
    CONSTRAINT business_branches_revision_check CHECK ((revision > 0)),
    CONSTRAINT business_branches_status_check CHECK ((status = ANY (ARRAY['active'::text, 'archived'::text])))
);

ALTER TABLE ONLY public.business_branches FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_branches OWNER TO builderad_owner;


-- Name: business_branches_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_branches ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.business_branches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



-- Name: business_command_receipts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_command_receipts (
    command_id uuid NOT NULL,
    business_id bigint,
    actor_user_id bigint,
    generation bigint NOT NULL,
    action text NOT NULL,
    payload_digest bytea NOT NULL,
    state text NOT NULL,
    result_ref jsonb NOT NULL,
    returned_versions jsonb NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    authority_selection_digest bytea,
    dependency_digest bytea,
    CONSTRAINT business_command_receipts_payload_digest_check CHECK ((octet_length(payload_digest) = 32)),
    CONSTRAINT business_command_receipts_state_check CHECK ((state = ANY (ARRAY['committed'::text, 'no_material_change'::text])))
);

ALTER TABLE ONLY public.business_command_receipts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_command_receipts OWNER TO builderad_owner;


-- Name: business_context_heads; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_context_heads (
    business_id bigint NOT NULL,
    display_name text NOT NULL,
    business_type_id bigint,
    context_version bigint DEFAULT 0 NOT NULL,
    CONSTRAINT business_context_heads_context_version_check CHECK ((context_version >= 0)),
    CONSTRAINT business_context_heads_display_name_check CHECK (((length(btrim(display_name)) >= 2) AND (length(btrim(display_name)) <= 120)))
);

ALTER TABLE ONLY public.business_context_heads FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_context_heads OWNER TO builderad_owner;


-- Name: business_context_transitions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_context_transitions (
    event_id uuid NOT NULL,
    business_id bigint NOT NULL,
    before_context_version bigint NOT NULL,
    after_context_version bigint NOT NULL,
    before_value jsonb NOT NULL,
    after_value jsonb NOT NULL,
    actor_id bigint NOT NULL,
    command_id uuid NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    origin_ref jsonb,
    CONSTRAINT business_context_transitions_before_context_version_check CHECK ((before_context_version >= 0)),
    CONSTRAINT business_context_transitions_check CHECK ((after_context_version = (before_context_version + 1))),
    CONSTRAINT business_context_transitions_event_id_check CHECK ((uuid_extract_version(event_id) = 7))
);

ALTER TABLE ONLY public.business_context_transitions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_context_transitions OWNER TO builderad_owner;


-- Name: business_current_intents; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_current_intents (
    business_id bigint NOT NULL,
    intent_revision bigint DEFAULT 0 NOT NULL,
    value jsonb,
    last_event_id uuid,
    CONSTRAINT business_current_intents_intent_revision_check CHECK ((intent_revision >= 0)),
    CONSTRAINT business_current_intents_value_check CHECK (((value IS NULL) OR (value = public.builderad_current_intent_normalize(value))))
);

ALTER TABLE ONLY public.business_current_intents FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_current_intents OWNER TO builderad_owner;


-- Name: business_data_use_bindings; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_data_use_bindings (
    binding_id uuid NOT NULL,
    business_id bigint NOT NULL,
    data_use_scope_id uuid NOT NULL,
    status text NOT NULL,
    revision bigint NOT NULL,
    qualification_ref uuid NOT NULL,
    CONSTRAINT business_data_use_bindings_binding_id_check CHECK ((uuid_extract_version(binding_id) = 7)),
    CONSTRAINT business_data_use_bindings_revision_check CHECK ((revision > 0)),
    CONSTRAINT business_data_use_bindings_status_check CHECK ((status = ANY (ARRAY['active'::text, 'ended'::text])))
);

ALTER TABLE ONLY public.business_data_use_bindings FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_data_use_bindings OWNER TO builderad_owner;


-- Name: business_declared_markets; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_declared_markets (
    business_id bigint NOT NULL,
    country_code character(2) NOT NULL
);

ALTER TABLE ONLY public.business_declared_markets FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_declared_markets OWNER TO builderad_owner;


-- Name: business_expenditure_summaries; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_expenditure_summaries (
    summary_id uuid NOT NULL,
    business_id bigint NOT NULL,
    processor_user_id bigint NOT NULL,
    output_scope_id uuid NOT NULL,
    purpose text NOT NULL,
    material_digest bytea NOT NULL,
    input_reads jsonb NOT NULL,
    input_selection jsonb NOT NULL,
    consent jsonb NOT NULL,
    valid_until timestamp with time zone NOT NULL,
    publication_manifest_id uuid,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT business_expenditure_summaries_consent_check CHECK ((consent = '{"derive": true, "retain_payload": false, "deliver_summary": true}'::jsonb)),
    CONSTRAINT business_expenditure_summaries_input_reads_check CHECK ((jsonb_array_length(input_reads) = 1)),
    CONSTRAINT business_expenditure_summaries_material_digest_check CHECK ((octet_length(material_digest) = 32)),
    CONSTRAINT business_expenditure_summaries_purpose_check CHECK (((length(purpose) >= 1) AND (length(purpose) <= 128)))
);

ALTER TABLE ONLY public.business_expenditure_summaries FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_expenditure_summaries OWNER TO builderad_owner;


-- Name: business_goal_preferences; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_goal_preferences (
    business_id bigint NOT NULL,
    preference_version bigint NOT NULL,
    value jsonb NOT NULL,
    actor_id bigint NOT NULL,
    updated_at timestamp with time zone NOT NULL,
    CONSTRAINT business_goal_preferences_preference_version_check CHECK ((preference_version > 0)),
    CONSTRAINT business_goal_preferences_value_check CHECK ((value = public.builderad_advertiser_goals_normalize(value)))
);

ALTER TABLE ONLY public.business_goal_preferences FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_goal_preferences OWNER TO builderad_owner;


-- Name: business_intent_transitions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_intent_transitions (
    event_id uuid NOT NULL,
    business_id bigint NOT NULL,
    before_revision bigint NOT NULL,
    after_revision bigint NOT NULL,
    before_context_version bigint NOT NULL,
    after_context_version bigint NOT NULL,
    before_value jsonb,
    after_value jsonb,
    actor_id bigint NOT NULL,
    command_id uuid NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    origin_ref jsonb,
    CONSTRAINT business_intent_transitions_after_value_check CHECK (((after_value IS NULL) OR (after_value = public.builderad_current_intent_normalize(after_value)))),
    CONSTRAINT business_intent_transitions_before_context_version_check CHECK ((before_context_version >= 0)),
    CONSTRAINT business_intent_transitions_before_revision_check CHECK ((before_revision >= 0)),
    CONSTRAINT business_intent_transitions_before_value_check CHECK (((before_value IS NULL) OR (before_value = public.builderad_current_intent_normalize(before_value)))),
    CONSTRAINT business_intent_transitions_check CHECK ((after_revision = (before_revision + 1))),
    CONSTRAINT business_intent_transitions_check1 CHECK ((after_context_version = (before_context_version + 1))),
    CONSTRAINT business_intent_transitions_event_id_check CHECK ((uuid_extract_version(event_id) = 7))
);

ALTER TABLE ONLY public.business_intent_transitions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_intent_transitions OWNER TO builderad_owner;


-- Name: business_market_transitions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_market_transitions (
    event_id uuid NOT NULL,
    business_id bigint NOT NULL,
    before_context_version bigint NOT NULL,
    after_context_version bigint NOT NULL,
    before_codes text[] NOT NULL,
    after_codes text[] NOT NULL,
    actor_id bigint NOT NULL,
    command_id uuid NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    origin_ref jsonb,
    CONSTRAINT business_market_transitions_before_context_version_check CHECK ((before_context_version >= 0)),
    CONSTRAINT business_market_transitions_check CHECK ((after_context_version = (before_context_version + 1))),
    CONSTRAINT business_market_transitions_event_id_check CHECK ((uuid_extract_version(event_id) = 7))
);

ALTER TABLE ONLY public.business_market_transitions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_market_transitions OWNER TO builderad_owner;


-- Name: business_practice_assessments; Type: TABLE; Schema: public; Owner: builderad_scope_executor
--

CREATE TABLE public.business_practice_assessments (
    assessment_id uuid NOT NULL,
    business_id bigint NOT NULL,
    practice_id uuid NOT NULL,
    practice_revision bigint NOT NULL,
    actor_id bigint NOT NULL,
    payload_digest bytea NOT NULL,
    result jsonb NOT NULL,
    material_ref jsonb NOT NULL,
    practice_digest bytea NOT NULL,
    recorded_at timestamp with time zone NOT NULL
);

ALTER TABLE ONLY public.business_practice_assessments FORCE ROW LEVEL SECURITY;




-- Name: business_preference_transitions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_preference_transitions (
    event_id uuid NOT NULL,
    business_id bigint NOT NULL,
    before_version bigint NOT NULL,
    after_version bigint NOT NULL,
    before_value jsonb,
    after_value jsonb NOT NULL,
    actor_id bigint NOT NULL,
    command_id uuid NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    origin_ref jsonb,
    CONSTRAINT business_preference_transitions_after_value_check CHECK ((after_value = public.builderad_advertiser_goals_normalize(after_value))),
    CONSTRAINT business_preference_transitions_before_value_check CHECK (((before_value IS NULL) OR (before_value = public.builderad_advertiser_goals_normalize(before_value)))),
    CONSTRAINT business_preference_transitions_before_version_check CHECK ((before_version >= 0)),
    CONSTRAINT business_preference_transitions_check CHECK ((after_version = (before_version + 1))),
    CONSTRAINT business_preference_transitions_event_id_check CHECK ((uuid_extract_version(event_id) = 7))
);

ALTER TABLE ONLY public.business_preference_transitions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_preference_transitions OWNER TO builderad_owner;


-- Name: business_promotion_priors; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_promotion_priors (
    id bigint NOT NULL,
    business_type_id bigint NOT NULL,
    provider character varying(20) NOT NULL,
    product character varying(64) NOT NULL,
    basis character varying(40) NOT NULL,
    documented_basis text NOT NULL,
    applicability text NOT NULL,
    limits text NOT NULL,
    rationale_ar character varying(600) NOT NULL,
    rationale_en character varying(600) NOT NULL,
    internal_rationale text NOT NULL,
    approved boolean DEFAULT false NOT NULL,
    active boolean DEFAULT false NOT NULL,
    approved_by bigint,
    approved_at timestamp with time zone,
    version bigint DEFAULT 1 NOT NULL,
    CONSTRAINT business_promotion_priors_applicability_check CHECK (((length(TRIM(BOTH FROM applicability)) >= 10) AND (length(TRIM(BOTH FROM applicability)) <= 2000))),
    CONSTRAINT business_promotion_priors_basis_check CHECK (((basis)::text = ANY ((ARRAY['ADMIN_APPROVED_HYPOTHESIS'::character varying, 'EVIDENCE_SUPPORTED_PRIOR'::character varying])::text[]))),
    CONSTRAINT business_promotion_priors_check CHECK (((approved AND (approved_by IS NOT NULL) AND (approved_at IS NOT NULL)) OR ((NOT approved) AND (approved_by IS NULL) AND (approved_at IS NULL)))),
    CONSTRAINT business_promotion_priors_documented_basis_check CHECK (((length(TRIM(BOTH FROM documented_basis)) >= 10) AND (length(TRIM(BOTH FROM documented_basis)) <= 4000))),
    CONSTRAINT business_promotion_priors_internal_rationale_check CHECK (((length(TRIM(BOTH FROM internal_rationale)) >= 10) AND (length(TRIM(BOTH FROM internal_rationale)) <= 4000))),
    CONSTRAINT business_promotion_priors_limits_check CHECK (((length(TRIM(BOTH FROM limits)) >= 10) AND (length(TRIM(BOTH FROM limits)) <= 2000))),
    CONSTRAINT business_promotion_priors_rationale_ar_check CHECK (((length(TRIM(BOTH FROM rationale_ar)) >= 10) AND (length(TRIM(BOTH FROM rationale_ar)) <= 600))),
    CONSTRAINT business_promotion_priors_rationale_en_check CHECK (((length(TRIM(BOTH FROM rationale_en)) >= 10) AND (length(TRIM(BOTH FROM rationale_en)) <= 600))),
    CONSTRAINT business_promotion_priors_version_check CHECK ((version > 0)),
    CONSTRAINT prior_product_provider CHECK ((((provider)::text = 'google'::text) AND ((product)::text = 'google.search'::text)))
);

ALTER TABLE ONLY public.business_promotion_priors FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_promotion_priors OWNER TO builderad_owner;


-- Name: business_promotion_priors_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_promotion_priors ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.business_promotion_priors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



-- Name: business_source_guidance; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_source_guidance (
    business_type_id bigint NOT NULL,
    provider character varying(20) NOT NULL,
    priority integer NOT NULL,
    rationale_ar character varying(600) NOT NULL,
    rationale_en character varying(600) NOT NULL,
    active boolean DEFAULT true NOT NULL,
    CONSTRAINT business_source_guidance_priority_check CHECK (((priority >= 0) AND (priority <= 1000))),
    CONSTRAINT business_source_guidance_provider_check CHECK (((provider)::text = ANY ((ARRAY['meta'::character varying, 'google'::character varying, 'snapchat'::character varying, 'tiktok'::character varying, 'x'::character varying])::text[]))),
    CONSTRAINT business_source_guidance_rationale_ar_check CHECK ((length(TRIM(BOTH FROM rationale_ar)) > 0)),
    CONSTRAINT business_source_guidance_rationale_en_check CHECK ((length(TRIM(BOTH FROM rationale_en)) > 0))
);

ALTER TABLE ONLY public.business_source_guidance FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_source_guidance OWNER TO builderad_owner;


-- Name: business_types; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.business_types (
    id bigint NOT NULL,
    key character varying(64) NOT NULL,
    name_ar character varying(120) NOT NULL,
    name_en character varying(120) NOT NULL,
    active boolean DEFAULT true NOT NULL,
    version bigint DEFAULT 1 NOT NULL,
    scope_lock_marker boolean DEFAULT false NOT NULL,
    CONSTRAINT business_types_key_check CHECK (((key)::text ~ '^[a-z][a-z0-9_]{0,63}$'::text)),
    CONSTRAINT business_types_name_ar_check CHECK (((length(TRIM(BOTH FROM name_ar)) >= 2) AND (length(TRIM(BOTH FROM name_ar)) <= 120))),
    CONSTRAINT business_types_name_en_check CHECK (((length(TRIM(BOTH FROM name_en)) >= 2) AND (length(TRIM(BOTH FROM name_en)) <= 120))),
    CONSTRAINT business_types_version_check CHECK ((version > 0))
);

ALTER TABLE ONLY public.business_types FORCE ROW LEVEL SECURITY;


ALTER TABLE public.business_types OWNER TO builderad_owner;


-- Name: business_types_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.business_types ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.business_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



-- Name: businesses; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.businesses (
    id bigint NOT NULL,
    public_id uuid NOT NULL,
    lifecycle text NOT NULL,
    lifecycle_revision bigint DEFAULT 0 NOT NULL,
    origin_kind text NOT NULL,
    created_by bigint NOT NULL,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT businesses_lifecycle_check CHECK ((lifecycle = ANY (ARRAY['declared_draft'::text, 'active'::text, 'suspended'::text, 'archived'::text]))),
    CONSTRAINT businesses_lifecycle_revision_check CHECK ((lifecycle_revision >= 0)),
    CONSTRAINT businesses_origin_kind_check CHECK ((origin_kind = ANY (ARRAY['self_declared_draft'::text, 'qualified_import'::text]))),
    CONSTRAINT businesses_public_id_check CHECK ((uuid_extract_version(public_id) = 7))
);

ALTER TABLE ONLY public.businesses FORCE ROW LEVEL SECURITY;


ALTER TABLE public.businesses OWNER TO builderad_owner;


-- Name: businesses_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

ALTER TABLE public.businesses ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.businesses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);



-- Name: client_data_boundaries; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.client_data_boundaries (
    boundary_id uuid NOT NULL,
    status text NOT NULL,
    revision bigint NOT NULL,
    descriptor jsonb NOT NULL,
    descriptor_schema_version smallint NOT NULL,
    qualification_ref uuid NOT NULL,
    CONSTRAINT client_data_boundaries_boundary_id_check CHECK ((uuid_extract_version(boundary_id) = 7)),
    CONSTRAINT client_data_boundaries_descriptor_check CHECK ((jsonb_typeof(descriptor) = 'object'::text)),
    CONSTRAINT client_data_boundaries_descriptor_schema_version_check CHECK ((descriptor_schema_version = 1)),
    CONSTRAINT client_data_boundaries_revision_check CHECK ((revision > 0)),
    CONSTRAINT client_data_boundaries_status_check CHECK ((status = ANY (ARRAY['active'::text, 'restricted'::text, 'archived'::text])))
);

ALTER TABLE ONLY public.client_data_boundaries FORCE ROW LEVEL SECURITY;


ALTER TABLE public.client_data_boundaries OWNER TO builderad_owner;


-- Name: commercial_audit_delivery; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_audit_delivery (
    obligation_id uuid NOT NULL,
    state character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    acknowledgment_id uuid,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT commercial_audit_delivery_attempts_check CHECK ((attempts >= 0)),
    CONSTRAINT commercial_audit_delivery_check CHECK ((((state)::text = 'acknowledged'::text) = (acknowledgment_id IS NOT NULL))),
    CONSTRAINT commercial_audit_delivery_state_check CHECK (((state)::text = ANY ((ARRAY['pending'::character varying, 'failed'::character varying, 'unknown'::character varying, 'acknowledged'::character varying])::text[])))
);

ALTER TABLE ONLY public.commercial_audit_delivery FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_audit_delivery OWNER TO builderad_owner;


-- Name: commercial_audit_obligations; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_audit_obligations (
    id uuid DEFAULT uuidv7() NOT NULL,
    command_id uuid NOT NULL,
    target_id uuid NOT NULL,
    business_id bigint,
    envelope jsonb NOT NULL,
    fingerprint character(64) NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT commercial_audit_obligations_envelope_check CHECK ((jsonb_typeof(envelope) = 'object'::text)),
    CONSTRAINT commercial_audit_obligations_fingerprint_check CHECK ((fingerprint ~ '^[a-f0-9]{64}$'::text))
);

ALTER TABLE ONLY public.commercial_audit_obligations FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_audit_obligations OWNER TO builderad_owner;


-- Name: commercial_beneficiary_bindings; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_beneficiary_bindings (
    id uuid DEFAULT uuidv7() NOT NULL,
    scope_id uuid NOT NULL,
    business_id bigint NOT NULL,
    decided_at timestamp with time zone NOT NULL,
    effective_at timestamp with time zone NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);

ALTER TABLE ONLY public.commercial_beneficiary_bindings FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_beneficiary_bindings OWNER TO builderad_owner;


-- Name: commercial_catalog_references; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_catalog_references (
    id uuid NOT NULL,
    market_id uuid NOT NULL,
    catalog_identity uuid NOT NULL,
    catalog_version character varying(80) NOT NULL,
    approval_reference character varying(120) NOT NULL,
    source_sha256 character(64) NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT commercial_catalog_references_approval_reference_check CHECK (((approval_reference)::text ~ '^[A-Za-z0-9_.:/-]{3,120}$'::text)),
    CONSTRAINT commercial_catalog_references_catalog_version_check CHECK ((length(TRIM(BOTH FROM catalog_version)) > 0)),
    CONSTRAINT commercial_catalog_references_id_check CHECK ((uuid_extract_version(id) = 7)),
    CONSTRAINT commercial_catalog_references_source_sha256_check CHECK ((source_sha256 ~ '^[a-f0-9]{64}$'::text))
);

ALTER TABLE ONLY public.commercial_catalog_references FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_catalog_references OWNER TO builderad_owner;


-- Name: commercial_command_results; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_command_results (
    command_id uuid NOT NULL,
    effect character varying(64) NOT NULL,
    target_id uuid NOT NULL,
    business_id bigint,
    request_fingerprint character(64) NOT NULL,
    result jsonb NOT NULL,
    actor_user_id bigint NOT NULL,
    actor_generation bigint NOT NULL,
    reason_code character varying(64) NOT NULL,
    policy_version character varying(32) DEFAULT 'SA01-V3'::character varying NOT NULL,
    decided_at timestamp with time zone NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT commercial_command_results_reason_code_check CHECK (((reason_code)::text ~ '^[a-z][a-z0-9_.-]{2,63}$'::text)),
    CONSTRAINT commercial_command_results_request_fingerprint_check CHECK ((request_fingerprint ~ '^[a-f0-9]{64}$'::text)),
    CONSTRAINT commercial_command_results_result_check CHECK ((jsonb_typeof(result) = 'object'::text))
);

ALTER TABLE ONLY public.commercial_command_results FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_command_results OWNER TO builderad_owner;


-- Name: commercial_founder_market_notes; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_founder_market_notes (
    command_id uuid NOT NULL,
    reason character varying(120) NOT NULL,
    CONSTRAINT commercial_founder_market_notes_reason_check CHECK ((length(TRIM(BOTH FROM reason)) > 0))
);

ALTER TABLE ONLY public.commercial_founder_market_notes FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_founder_market_notes OWNER TO builderad_owner;


-- Name: commercial_market_assignability; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_market_assignability (
    market_id uuid NOT NULL,
    configuration_id uuid,
    revision bigint DEFAULT 1 NOT NULL,
    changed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT commercial_market_assignability_revision_check CHECK ((revision > 0))
);

ALTER TABLE ONLY public.commercial_market_assignability FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_market_assignability OWNER TO builderad_owner;


-- Name: commercial_market_assignments; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_market_assignments (
    id uuid DEFAULT uuidv7() NOT NULL,
    scope_id uuid NOT NULL,
    business_id bigint NOT NULL,
    market_id uuid NOT NULL,
    configuration_id uuid NOT NULL,
    catalog_reference_id uuid NOT NULL,
    denomination_code character(3) NOT NULL,
    decided_at timestamp with time zone NOT NULL,
    effective_at timestamp with time zone NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);

ALTER TABLE ONLY public.commercial_market_assignments FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_market_assignments OWNER TO builderad_owner;


-- Name: commercial_market_configuration_versions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_market_configuration_versions (
    id uuid NOT NULL,
    market_id uuid NOT NULL,
    version integer NOT NULL,
    catalog_reference_id uuid,
    denomination_code character(3),
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT commercial_market_configuration_version_denomination_code_check CHECK ((denomination_code ~ '^[A-Z]{3}$'::text)),
    CONSTRAINT commercial_market_configuration_versions_id_check CHECK ((uuid_extract_version(id) = 7)),
    CONSTRAINT commercial_market_configuration_versions_version_check CHECK ((version > 0))
);

ALTER TABLE ONLY public.commercial_market_configuration_versions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_market_configuration_versions OWNER TO builderad_owner;


-- Name: commercial_market_countries; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_market_countries (
    market_id uuid NOT NULL,
    country_code character(2) NOT NULL,
    command_id uuid NOT NULL,
    CONSTRAINT commercial_market_countries_country_code_check CHECK ((country_code = ANY (ARRAY['SA'::bpchar, 'AE'::bpchar, 'KW'::bpchar, 'QA'::bpchar, 'BH'::bpchar, 'OM'::bpchar, 'JO'::bpchar])))
);

ALTER TABLE ONLY public.commercial_market_countries FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_market_countries OWNER TO builderad_owner;


-- Name: commercial_markets; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_markets (
    id uuid NOT NULL,
    label character varying(120) NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT commercial_markets_id_check CHECK ((uuid_extract_version(id) = 7)),
    CONSTRAINT commercial_markets_label_check CHECK ((length(TRIM(BOTH FROM label)) > 0))
);

ALTER TABLE ONLY public.commercial_markets FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_markets OWNER TO builderad_owner;


-- Name: commercial_scopes; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.commercial_scopes (
    id uuid NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT commercial_scopes_id_check CHECK ((uuid_extract_version(id) = 7))
);

ALTER TABLE ONLY public.commercial_scopes FORCE ROW LEVEL SECURITY;


ALTER TABLE public.commercial_scopes OWNER TO builderad_owner;


-- Name: content_article_revisions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.content_article_revisions (
    id bigint NOT NULL,
    article_id bigint NOT NULL,
    revision integer NOT NULL,
    title_ar text NOT NULL,
    title_en text NOT NULL,
    summary_ar text,
    summary_en text,
    body_ar text DEFAULT ''::text NOT NULL,
    body_en text DEFAULT ''::text NOT NULL,
    image_path text,
    image_alt_ar text,
    image_alt_en text,
    seo_title_ar text,
    seo_title_en text,
    seo_description_ar text,
    seo_description_en text,
    state text DEFAULT 'draft'::text NOT NULL,
    created_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    slug_ar text NOT NULL,
    slug_en text NOT NULL,
    category_id bigint,
    "position" integer NOT NULL,
    CONSTRAINT content_article_revisions_position_check CHECK (("position" >= 0)),
    CONSTRAINT content_article_revisions_revision_check CHECK ((revision >= 1)),
    CONSTRAINT content_article_revisions_state_check CHECK ((state = ANY (ARRAY['draft'::text, 'published'::text, 'superseded'::text])))
);


ALTER TABLE public.content_article_revisions OWNER TO builderad_owner;


-- Name: content_article_revisions_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.content_article_revisions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.content_article_revisions_id_seq OWNER TO builderad_owner;


-- Name: content_article_revisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.content_article_revisions_id_seq OWNED BY public.content_article_revisions.id;



-- Name: content_articles; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.content_articles (
    id bigint NOT NULL,
    public_id uuid DEFAULT gen_random_uuid() NOT NULL,
    category_id bigint,
    slug_ar text NOT NULL,
    slug_en text NOT NULL,
    published_revision_id bigint,
    draft_revision_id bigint,
    published_at timestamp with time zone,
    "position" integer DEFAULT 0 NOT NULL,
    deleted_at timestamp with time zone,
    created_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT content_articles_position_check CHECK (("position" >= 0))
);


ALTER TABLE public.content_articles OWNER TO builderad_owner;


-- Name: content_articles_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.content_articles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.content_articles_id_seq OWNER TO builderad_owner;


-- Name: content_articles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.content_articles_id_seq OWNED BY public.content_articles.id;



-- Name: content_categories; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.content_categories (
    id bigint NOT NULL,
    public_id uuid DEFAULT gen_random_uuid() NOT NULL,
    slug_ar text NOT NULL,
    slug_en text NOT NULL,
    name_ar text NOT NULL,
    name_en text NOT NULL,
    description_ar text,
    description_en text,
    "position" integer DEFAULT 0 NOT NULL,
    visible boolean DEFAULT true NOT NULL,
    deleted_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT content_categories_position_check CHECK (("position" >= 0))
);


ALTER TABLE public.content_categories OWNER TO builderad_owner;


-- Name: content_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.content_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.content_categories_id_seq OWNER TO builderad_owner;


-- Name: content_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.content_categories_id_seq OWNED BY public.content_categories.id;



-- Name: content_footer_settings; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.content_footer_settings (
    id smallint NOT NULL,
    revision bigint DEFAULT 0 NOT NULL,
    description_ar text DEFAULT ''::text NOT NULL,
    description_en text DEFAULT ''::text NOT NULL,
    copyright_ar text DEFAULT ''::text NOT NULL,
    copyright_en text DEFAULT ''::text NOT NULL,
    social_links jsonb DEFAULT '[]'::jsonb NOT NULL,
    show_sections boolean DEFAULT true NOT NULL,
    published_by bigint,
    published_at timestamp with time zone,
    CONSTRAINT content_footer_settings_id_check CHECK ((id = 1)),
    CONSTRAINT content_footer_settings_revision_check CHECK ((revision >= 0)),
    CONSTRAINT content_footer_settings_social_links_check CHECK ((jsonb_typeof(social_links) = 'array'::text))
);


ALTER TABLE public.content_footer_settings OWNER TO builderad_owner;


-- Name: content_navigation_links; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.content_navigation_links (
    id bigint NOT NULL,
    public_id uuid DEFAULT gen_random_uuid() NOT NULL,
    section_id bigint NOT NULL,
    label_ar text,
    label_en text,
    link_type text NOT NULL,
    page_id bigint,
    article_id bigint,
    internal_path text,
    external_url text,
    "position" integer DEFAULT 0 NOT NULL,
    visible boolean DEFAULT true NOT NULL,
    open_in_new_tab boolean DEFAULT false NOT NULL,
    deleted_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT content_navigation_links_external_https CHECK (((external_url IS NULL) OR (external_url ~ '^https://[^[:space:]]+$'::text))),
    CONSTRAINT content_navigation_links_link_type_check CHECK ((link_type = ANY (ARRAY['page'::text, 'article'::text, 'internal'::text, 'external'::text]))),
    CONSTRAINT content_navigation_links_position_check CHECK (("position" >= 0)),
    CONSTRAINT content_navigation_links_target CHECK ((((link_type = 'page'::text) AND (page_id IS NOT NULL) AND (article_id IS NULL) AND (internal_path IS NULL) AND (external_url IS NULL)) OR ((link_type = 'article'::text) AND (article_id IS NOT NULL) AND (page_id IS NULL) AND (internal_path IS NULL) AND (external_url IS NULL)) OR ((link_type = 'internal'::text) AND (internal_path IS NOT NULL) AND (page_id IS NULL) AND (article_id IS NULL) AND (external_url IS NULL)) OR ((link_type = 'external'::text) AND (external_url IS NOT NULL) AND (page_id IS NULL) AND (article_id IS NULL) AND (internal_path IS NULL))))
);


ALTER TABLE public.content_navigation_links OWNER TO builderad_owner;


-- Name: content_navigation_links_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.content_navigation_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.content_navigation_links_id_seq OWNER TO builderad_owner;


-- Name: content_navigation_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.content_navigation_links_id_seq OWNED BY public.content_navigation_links.id;



-- Name: content_navigation_sections; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.content_navigation_sections (
    id bigint NOT NULL,
    public_id uuid DEFAULT gen_random_uuid() NOT NULL,
    name_ar text NOT NULL,
    name_en text NOT NULL,
    placement text NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    visible boolean DEFAULT true NOT NULL,
    deleted_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT content_navigation_sections_placement_check CHECK ((placement = ANY (ARRAY['header'::text, 'footer'::text]))),
    CONSTRAINT content_navigation_sections_position_check CHECK (("position" >= 0))
);


ALTER TABLE public.content_navigation_sections OWNER TO builderad_owner;


-- Name: content_navigation_sections_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.content_navigation_sections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.content_navigation_sections_id_seq OWNER TO builderad_owner;


-- Name: content_navigation_sections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.content_navigation_sections_id_seq OWNED BY public.content_navigation_sections.id;



-- Name: content_page_revisions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.content_page_revisions (
    id bigint NOT NULL,
    page_id bigint NOT NULL,
    revision integer NOT NULL,
    title_ar text NOT NULL,
    title_en text NOT NULL,
    excerpt_ar text,
    excerpt_en text,
    body_ar text DEFAULT ''::text NOT NULL,
    body_en text DEFAULT ''::text NOT NULL,
    seo_title_ar text,
    seo_title_en text,
    seo_description_ar text,
    seo_description_en text,
    state text DEFAULT 'draft'::text NOT NULL,
    created_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    slug_ar text NOT NULL,
    slug_en text NOT NULL,
    "position" integer NOT NULL,
    CONSTRAINT content_page_revisions_position_check CHECK (("position" >= 0)),
    CONSTRAINT content_page_revisions_revision_check CHECK ((revision >= 1)),
    CONSTRAINT content_page_revisions_state_check CHECK ((state = ANY (ARRAY['draft'::text, 'published'::text, 'superseded'::text])))
);


ALTER TABLE public.content_page_revisions OWNER TO builderad_owner;


-- Name: content_page_revisions_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.content_page_revisions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.content_page_revisions_id_seq OWNER TO builderad_owner;


-- Name: content_page_revisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.content_page_revisions_id_seq OWNED BY public.content_page_revisions.id;



-- Name: content_pages; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.content_pages (
    id bigint NOT NULL,
    public_id uuid DEFAULT gen_random_uuid() NOT NULL,
    slug_ar text NOT NULL,
    slug_en text NOT NULL,
    published_revision_id bigint,
    draft_revision_id bigint,
    published_at timestamp with time zone,
    "position" integer DEFAULT 0 NOT NULL,
    deleted_at timestamp with time zone,
    created_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT content_pages_position_check CHECK (("position" >= 0))
);


ALTER TABLE public.content_pages OWNER TO builderad_owner;


-- Name: content_pages_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.content_pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.content_pages_id_seq OWNER TO builderad_owner;


-- Name: content_pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.content_pages_id_seq OWNED BY public.content_pages.id;



-- Name: country_references; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.country_references (
    code character varying(2) NOT NULL,
    name_ar text NOT NULL,
    name_en text NOT NULL,
    CONSTRAINT country_references_code_check CHECK (((code)::text ~ '^[A-Z]{2}$'::text)),
    CONSTRAINT country_references_name_ar_check CHECK ((length(TRIM(BOTH FROM name_ar)) > 0)),
    CONSTRAINT country_references_name_en_check CHECK ((length(TRIM(BOTH FROM name_en)) > 0))
);


ALTER TABLE public.country_references OWNER TO builderad_owner;


-- Name: current_intent_timezone_catalog; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.current_intent_timezone_catalog (
    id smallint NOT NULL,
    names jsonb NOT NULL,
    postgres_version integer NOT NULL,
    captured_at timestamp with time zone NOT NULL,
    CONSTRAINT current_intent_timezone_catalog_id_check CHECK ((id = 1)),
    CONSTRAINT current_intent_timezone_catalog_names_check CHECK (((jsonb_typeof(names) = 'array'::text) AND (jsonb_array_length(names) > 0)))
);


ALTER TABLE public.current_intent_timezone_catalog OWNER TO builderad_owner;


-- Name: customer_administration_events; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.customer_administration_events (
    command_id uuid NOT NULL,
    actor_user_id bigint NOT NULL,
    subject_user_id bigint,
    business_id bigint,
    decision text NOT NULL,
    reason character varying(128) NOT NULL,
    expected_status text NOT NULL,
    expected_revision bigint NOT NULL,
    prior_status text NOT NULL,
    new_status text NOT NULL,
    new_revision bigint NOT NULL,
    occurred_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT customer_administration_events_check CHECK (((subject_user_id IS NULL) <> (business_id IS NULL))),
    CONSTRAINT customer_administration_events_decision_check CHECK ((decision = ANY (ARRAY['suspend'::text, 'reactivate'::text]))),
    CONSTRAINT customer_administration_events_reason_check CHECK ((length(TRIM(BOTH FROM reason)) > 0))
);

ALTER TABLE ONLY public.customer_administration_events FORCE ROW LEVEL SECURITY;


ALTER TABLE public.customer_administration_events OWNER TO builderad_owner;


-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid uuid NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO builderad_owner;


-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO builderad_owner;


-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;



-- Name: global_appearance; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.global_appearance (
    id smallint NOT NULL,
    revision bigint NOT NULL,
    primary_color text NOT NULL,
    secondary_color text NOT NULL,
    published_by bigint,
    published_at timestamp with time zone,
    CONSTRAINT global_appearance_id_check CHECK ((id = 1)),
    CONSTRAINT global_appearance_primary_color_check CHECK ((primary_color ~ '^#[0-9a-f]{6}$'::text)),
    CONSTRAINT global_appearance_revision_check CHECK ((revision >= 0)),
    CONSTRAINT global_appearance_secondary_color_check CHECK ((secondary_color ~ '^#[0-9a-f]{6}$'::text))
);


ALTER TABLE public.global_appearance OWNER TO builderad_owner;


-- Name: google_read_attempts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.google_read_attempts (
    namespace text NOT NULL,
    command_id uuid NOT NULL,
    generation bigint NOT NULL,
    request_fingerprint text NOT NULL,
    result_fingerprint text NOT NULL,
    encrypted_result text NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT google_read_attempts_encrypted_result_check CHECK (((length(encrypted_result) >= 1) AND (length(encrypted_result) <= 65536))),
    CONSTRAINT google_read_attempts_request_fingerprint_check CHECK ((request_fingerprint ~ '^[a-f0-9]{64}$'::text)),
    CONSTRAINT google_read_attempts_result_fingerprint_check CHECK ((result_fingerprint ~ '^[a-f0-9]{64}$'::text))
);

ALTER TABLE ONLY public.google_read_attempts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.google_read_attempts OWNER TO builderad_owner;


-- Name: inquiry_evidence_enrollments; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.inquiry_evidence_enrollments (
    qualification_id uuid NOT NULL,
    business_id bigint NOT NULL,
    actor_user_id bigint NOT NULL,
    generation bigint NOT NULL,
    basis_qualification_id uuid NOT NULL,
    basis_revision bigint NOT NULL,
    policy_version integer NOT NULL,
    status text NOT NULL,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT inquiry_evidence_enrollments_basis_revision_check CHECK ((basis_revision > 0)),
    CONSTRAINT inquiry_evidence_enrollments_policy_version_check CHECK ((policy_version = 1)),
    CONSTRAINT inquiry_evidence_enrollments_status_check CHECK ((status = ANY (ARRAY['active'::text, 'withdrawn'::text])))
);

ALTER TABLE ONLY public.inquiry_evidence_enrollments FORCE ROW LEVEL SECURITY;


ALTER TABLE public.inquiry_evidence_enrollments OWNER TO builderad_owner;


-- Name: inquiry_evidence_heads; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.inquiry_evidence_heads (
    declaration_id uuid NOT NULL,
    business_id bigint NOT NULL,
    issuer_user_id bigint NOT NULL,
    revision bigint NOT NULL,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT inquiry_evidence_heads_declaration_id_check CHECK ((uuid_extract_version(declaration_id) = 7)),
    CONSTRAINT inquiry_evidence_heads_revision_check CHECK ((revision > 0))
);

ALTER TABLE ONLY public.inquiry_evidence_heads FORCE ROW LEVEL SECURITY;


ALTER TABLE public.inquiry_evidence_heads OWNER TO builderad_owner;


-- Name: inquiry_evidence_revisions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.inquiry_evidence_revisions (
    declaration_id uuid NOT NULL,
    revision bigint NOT NULL,
    actor_user_id bigint NOT NULL,
    qualification_id uuid NOT NULL,
    command_id uuid NOT NULL,
    state text NOT NULL,
    reason text NOT NULL,
    value jsonb,
    recorded_at timestamp with time zone NOT NULL,
    CONSTRAINT inquiry_evidence_revisions_check CHECK ((((state = 'source_asserted'::text) AND (value IS NOT NULL) AND (jsonb_typeof(value) = 'object'::text)) OR ((state = 'withdrawn'::text) AND (value IS NULL)))),
    CONSTRAINT inquiry_evidence_revisions_reason_check CHECK ((reason = ANY (ARRAY['initial'::text, 'correction'::text, 'withdrawal'::text]))),
    CONSTRAINT inquiry_evidence_revisions_revision_check CHECK ((revision > 0)),
    CONSTRAINT inquiry_evidence_revisions_state_check CHECK ((state = ANY (ARRAY['source_asserted'::text, 'withdrawn'::text])))
);

ALTER TABLE ONLY public.inquiry_evidence_revisions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.inquiry_evidence_revisions OWNER TO builderad_owner;


-- Name: internal_personnel; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.internal_personnel (
    user_id bigint NOT NULL,
    kind character varying(16) NOT NULL,
    status character varying(16) NOT NULL,
    attested_by bigint,
    attested_at timestamp(0) with time zone,
    factor_independence_attested_at timestamp(0) with time zone,
    factor_independence_attested_by bigint,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    runtime_lock_marker boolean DEFAULT false NOT NULL,
    CONSTRAINT internal_personnel_kind_check CHECK (((kind)::text = ANY ((ARRAY['founder'::character varying, 'employee'::character varying])::text[]))),
    CONSTRAINT internal_personnel_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'active'::character varying, 'revoked'::character varying])::text[])))
);


ALTER TABLE public.internal_personnel OWNER TO builderad_owner;


-- Name: jobs; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO builderad_owner;


-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO builderad_owner;


-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;



-- Name: market_business_finding_captures; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_business_finding_captures (
    artifact_id uuid NOT NULL,
    processing_run_id uuid NOT NULL,
    business_id bigint NOT NULL,
    processor_user_id bigint NOT NULL,
    output_scope_id uuid NOT NULL,
    purpose text NOT NULL,
    material_digest bytea NOT NULL,
    input_reads jsonb NOT NULL,
    continuation_rights jsonb NOT NULL,
    context_snapshot jsonb,
    state text NOT NULL,
    publication_manifest_id uuid,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT market_business_finding_captures_check CHECK (((state = ANY (ARRAY['published'::text, 'retention_removed'::text])) = (publication_manifest_id IS NOT NULL))),
    CONSTRAINT market_business_finding_captures_material_digest_check CHECK ((octet_length(material_digest) = 32)),
    CONSTRAINT market_business_finding_captures_purpose_check CHECK (((length(purpose) >= 1) AND (length(purpose) <= 128))),
    CONSTRAINT market_business_finding_captures_state_check CHECK ((state = ANY (ARRAY['prepared'::text, 'published'::text, 'retention_removed'::text])))
);

ALTER TABLE ONLY public.market_business_finding_captures FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_business_finding_captures OWNER TO builderad_owner;


-- Name: market_evidence_heads; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_evidence_heads (
    evidence_id uuid NOT NULL,
    source_id uuid NOT NULL,
    record_key text NOT NULL,
    current_revision bigint NOT NULL,
    CONSTRAINT market_evidence_heads_current_revision_check CHECK ((current_revision > 0)),
    CONSTRAINT market_evidence_heads_evidence_id_check CHECK ((uuid_extract_version(evidence_id) = 7)),
    CONSTRAINT market_evidence_heads_record_key_check CHECK (((length(record_key) >= 1) AND (length(record_key) <= 180)))
);

ALTER TABLE ONLY public.market_evidence_heads FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_evidence_heads OWNER TO builderad_owner;


-- Name: market_evidence_revisions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_evidence_revisions (
    evidence_id uuid NOT NULL,
    revision bigint NOT NULL,
    prior_revision bigint,
    content_digest bytea NOT NULL,
    evidence_type text NOT NULL,
    fields jsonb NOT NULL,
    coverage text NOT NULL,
    provenance jsonb NOT NULL,
    effective_at timestamp with time zone NOT NULL,
    observed_at timestamp with time zone NOT NULL,
    valid_until timestamp with time zone,
    command_id uuid NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    CONSTRAINT market_evidence_revisions_check CHECK ((NOT (prior_revision IS DISTINCT FROM NULLIF((revision - 1), 0)))),
    CONSTRAINT market_evidence_revisions_check1 CHECK ((isfinite(effective_at) AND isfinite(observed_at) AND isfinite(recorded_at) AND ((valid_until IS NULL) OR (isfinite(valid_until) AND (valid_until > effective_at))))),
    CONSTRAINT market_evidence_revisions_content_digest_check CHECK ((octet_length(content_digest) = 32)),
    CONSTRAINT market_evidence_revisions_coverage_check CHECK ((coverage = ANY (ARRAY['COMPLETE'::text, 'PARTIAL'::text, 'FAILED'::text, 'UNKNOWN'::text, 'PENDING'::text]))),
    CONSTRAINT market_evidence_revisions_evidence_type_check CHECK ((evidence_type = ANY (ARRAY['market.offer:1'::text, 'market.interest_signal:1'::text, 'market.offer:2'::text, 'market.interest_signal:2'::text]))),
    CONSTRAINT market_evidence_revisions_fields_check CHECK ((jsonb_typeof(fields) = 'object'::text)),
    CONSTRAINT market_evidence_revisions_provenance_check CHECK ((jsonb_typeof(provenance) = 'object'::text)),
    CONSTRAINT market_evidence_revisions_revision_check CHECK ((revision > 0))
);

ALTER TABLE ONLY public.market_evidence_revisions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_evidence_revisions OWNER TO builderad_owner;


-- Name: market_evidence_transitions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_evidence_transitions (
    command_id uuid NOT NULL,
    evidence_id uuid NOT NULL,
    from_revision bigint,
    to_revision bigint NOT NULL,
    changed_fields text[] NOT NULL,
    reason text NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    CONSTRAINT market_evidence_transitions_reason_check CHECK (((length(reason) >= 1) AND (length(reason) <= 600)))
);

ALTER TABLE ONLY public.market_evidence_transitions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_evidence_transitions OWNER TO builderad_owner;


-- Name: market_source_custody; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.market_source_custody (
    source_id uuid NOT NULL,
    revision bigint NOT NULL,
    status text NOT NULL,
    policy_id text NOT NULL,
    policy_version bigint NOT NULL,
    evidence_ref text NOT NULL,
    evidence_digest bytea NOT NULL,
    allowed_operations text[] NOT NULL,
    allowed_purposes text[] NOT NULL,
    allowed_fields text[] NOT NULL,
    valid_from timestamp with time zone NOT NULL,
    valid_until timestamp with time zone,
    CONSTRAINT market_source_custody_check CHECK ((isfinite(valid_from) AND ((valid_until IS NULL) OR (isfinite(valid_until) AND (valid_until > valid_from))))),
    CONSTRAINT market_source_custody_evidence_digest_check CHECK ((octet_length(evidence_digest) = 32)),
    CONSTRAINT market_source_custody_evidence_ref_check CHECK (((length(evidence_ref) >= 1) AND (length(evidence_ref) <= 4096))),
    CONSTRAINT market_source_custody_policy_id_check CHECK (((length(policy_id) >= 1) AND (length(policy_id) <= 180))),
    CONSTRAINT market_source_custody_policy_version_check CHECK ((policy_version > 0)),
    CONSTRAINT market_source_custody_revision_check CHECK ((revision > 0)),
    CONSTRAINT market_source_custody_status_check CHECK ((status = ANY (ARRAY['active'::text, 'restricted'::text, 'revoked'::text])))
);

ALTER TABLE ONLY public.market_source_custody FORCE ROW LEVEL SECURITY;


ALTER TABLE public.market_source_custody OWNER TO builderad_owner;


-- Name: material_lineage_manifests; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.material_lineage_manifests (
    manifest_id uuid NOT NULL,
    material_namespace text NOT NULL,
    material_key text NOT NULL,
    material_version text NOT NULL,
    kind text NOT NULL,
    producer_receipt_ref text NOT NULL,
    resolver_contract_version bigint NOT NULL,
    material_digest bytea NOT NULL,
    direct_dependency_count integer NOT NULL,
    direct_dependency_digest bytea NOT NULL,
    closure_digest bytea NOT NULL,
    state text NOT NULL,
    recorded_at timestamp with time zone NOT NULL,
    CONSTRAINT material_lineage_manifests_closure_digest_check CHECK ((octet_length(closure_digest) = 32)),
    CONSTRAINT material_lineage_manifests_direct_dependency_count_check CHECK ((direct_dependency_count >= 0)),
    CONSTRAINT material_lineage_manifests_direct_dependency_digest_check CHECK ((octet_length(direct_dependency_digest) = 32)),
    CONSTRAINT material_lineage_manifests_kind_check CHECK ((kind = ANY (ARRAY['original'::text, 'derived'::text]))),
    CONSTRAINT material_lineage_manifests_manifest_id_check CHECK ((uuid_extract_version(manifest_id) = 7)),
    CONSTRAINT material_lineage_manifests_material_digest_check CHECK ((octet_length(material_digest) = 32)),
    CONSTRAINT material_lineage_manifests_resolver_contract_version_check CHECK ((resolver_contract_version > 0)),
    CONSTRAINT material_lineage_manifests_state_check CHECK ((state = 'sealed'::text))
);

ALTER TABLE ONLY public.material_lineage_manifests FORCE ROW LEVEL SECURITY;


ALTER TABLE public.material_lineage_manifests OWNER TO builderad_owner;


-- Name: material_use_dependencies; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.material_use_dependencies (
    dependency_id uuid NOT NULL,
    material_namespace text NOT NULL,
    material_key text NOT NULL,
    material_version text NOT NULL,
    input_namespace text NOT NULL,
    input_key text NOT NULL,
    input_version text NOT NULL,
    data_use_scope_id uuid NOT NULL,
    policy_id text NOT NULL,
    policy_version bigint NOT NULL,
    lineage_digest bytea NOT NULL,
    CONSTRAINT material_use_dependencies_dependency_id_check CHECK ((uuid_extract_version(dependency_id) = 7)),
    CONSTRAINT material_use_dependencies_lineage_digest_check CHECK ((octet_length(lineage_digest) = 32)),
    CONSTRAINT material_use_dependencies_policy_version_check CHECK ((policy_version > 0))
);

ALTER TABLE ONLY public.material_use_dependencies FORCE ROW LEVEL SECURITY;


ALTER TABLE public.material_use_dependencies OWNER TO builderad_owner;


-- Name: meta_read_attempts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.meta_read_attempts (
    namespace text NOT NULL,
    command_id uuid NOT NULL,
    generation bigint NOT NULL,
    request_fingerprint text NOT NULL,
    result_fingerprint text NOT NULL,
    encrypted_result text NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT meta_read_attempts_encrypted_result_check CHECK (((length(encrypted_result) >= 1) AND (length(encrypted_result) <= 65536))),
    CONSTRAINT meta_read_attempts_request_fingerprint_check CHECK ((request_fingerprint ~ '^[a-f0-9]{64}$'::text)),
    CONSTRAINT meta_read_attempts_result_fingerprint_check CHECK ((result_fingerprint ~ '^[a-f0-9]{64}$'::text))
);

ALTER TABLE ONLY public.meta_read_attempts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.meta_read_attempts OWNER TO builderad_owner;


-- Name: migration_cohort_control; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.migration_cohort_control (
    cohort_id uuid NOT NULL,
    mode text NOT NULL,
    epoch bigint DEFAULT 0 NOT NULL,
    checkpoint_digest bytea,
    CONSTRAINT migration_cohort_control_cohort_id_check CHECK ((uuid_extract_version(cohort_id) = 7)),
    CONSTRAINT migration_cohort_control_epoch_check CHECK ((epoch >= 0)),
    CONSTRAINT migration_cohort_control_native_contract_1 CHECK (mode IN ('V2_ACTIVE','RECOVERY_HOLD'))
);

ALTER TABLE ONLY public.migration_cohort_control FORCE ROW LEVEL SECURITY;


ALTER TABLE public.migration_cohort_control OWNER TO builderad_owner;


-- Name: migration_cohort_members; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.migration_cohort_members (
    member_id uuid NOT NULL,
    cohort_id uuid NOT NULL,
    kind text NOT NULL,
    business_id bigint,
    CONSTRAINT migration_cohort_members_member_id_check CHECK ((uuid_extract_version(member_id) = 7)),
    CONSTRAINT migration_cohort_members_native_contract_1 CHECK (kind='business' AND business_id IS NOT NULL)
);

ALTER TABLE ONLY public.migration_cohort_members FORCE ROW LEVEL SECURITY;


ALTER TABLE public.migration_cohort_members OWNER TO builderad_owner;


-- Name: object_purpose_business_context_refs; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.object_purpose_business_context_refs (
    ref_id uuid NOT NULL,
    native_revision_id uuid CONSTRAINT object_purpose_business_context_ref_native_revision_id_not_null NOT NULL,
    business_id bigint NOT NULL,
    context_version bigint NOT NULL,
    reference_origin text NOT NULL,
    CONSTRAINT object_purpose_business_context_refs_context_version_check CHECK ((context_version >= 0)),
    CONSTRAINT object_purpose_business_context_refs_ref_id_check CHECK ((uuid_extract_version(ref_id) = 7)),
    CONSTRAINT object_purpose_business_context_refs_native_contract_1 CHECK (reference_origin='v2')
);

ALTER TABLE ONLY public.object_purpose_business_context_refs FORCE ROW LEVEL SECURITY;


ALTER TABLE public.object_purpose_business_context_refs OWNER TO builderad_owner;


-- Name: object_purpose_command_receipts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.object_purpose_command_receipts (
    command_id uuid NOT NULL,
    actor_user_id bigint NOT NULL,
    fingerprint text NOT NULL,
    result jsonb NOT NULL,
    purpose_id uuid NOT NULL,
    revision_id uuid NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    business_id bigint,
    CONSTRAINT object_purpose_command_receipts_fingerprint_check CHECK ((fingerprint ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT object_purpose_command_receipts_native_contract_1 CHECK (business_id IS NOT NULL)
);

ALTER TABLE ONLY public.object_purpose_command_receipts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.object_purpose_command_receipts OWNER TO builderad_owner;


-- Name: object_purpose_revisions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.object_purpose_revisions (
    purpose_id uuid NOT NULL,
    version bigint NOT NULL,
    revision_id uuid DEFAULT uuidv7() NOT NULL,
    prior_version bigint,
    supersedes_revision_id uuid,
    operation text NOT NULL,
    effective_from timestamp with time zone NOT NULL,
    effective_until timestamp with time zone,
    snapshot jsonb NOT NULL,
    actor_user_id bigint NOT NULL,
    actor_generation bigint NOT NULL,
    confirmations jsonb NOT NULL,
    command_id uuid NOT NULL,
    reason text,
    affected_revision_ids jsonb NOT NULL,
    accepted_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    business_id bigint,
    business_authority_epoch bigint,
    CONSTRAINT object_purpose_revisions_check CHECK (((version > 0) AND (NOT (prior_version IS DISTINCT FROM NULLIF((version - 1), 0))))),
    CONSTRAINT object_purpose_revisions_check1 CHECK ((((version = 1) AND (operation = 'declare'::text) AND (supersedes_revision_id IS NULL)) OR ((version > 1) AND (operation <> 'declare'::text) AND (supersedes_revision_id IS NOT NULL)))),
    CONSTRAINT object_purpose_revisions_check2 CHECK (((effective_until IS NULL) OR (effective_until > effective_from))),
    CONSTRAINT object_purpose_revisions_operation_check CHECK ((operation = ANY (ARRAY['declare'::text, 'replace'::text, 'correct'::text, 'withdraw'::text]))),
    CONSTRAINT object_purpose_revisions_revision_id_check CHECK ((uuid_extract_version(revision_id) = 7)),
    CONSTRAINT object_purpose_revisions_snapshot_check CHECK ((snapshot = public.builderad_object_purpose_normalize(snapshot))),
    CONSTRAINT object_purpose_revisions_native_contract_1 CHECK (business_id IS NOT NULL AND business_authority_epoch>=0)
);

ALTER TABLE ONLY public.object_purpose_revisions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.object_purpose_revisions OWNER TO builderad_owner;


-- Name: object_purposes; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.object_purposes (
    id uuid DEFAULT uuidv7() NOT NULL,
    head_version bigint DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    business_id bigint,
    CONSTRAINT object_purposes_head_version_check CHECK ((head_version >= 0)),
    CONSTRAINT object_purposes_id_check CHECK ((uuid_extract_version(id) = 7)),
    CONSTRAINT object_purposes_native_contract_1 CHECK (business_id IS NOT NULL)
);

ALTER TABLE ONLY public.object_purposes FORCE ROW LEVEL SECURITY;


ALTER TABLE public.object_purposes OWNER TO builderad_owner;


-- Name: passkeys; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.passkeys (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    credential_id character varying(255) NOT NULL,
    credential json NOT NULL,
    last_used_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


ALTER TABLE public.passkeys OWNER TO builderad_owner;


-- Name: passkeys_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.passkeys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.passkeys_id_seq OWNER TO builderad_owner;


-- Name: passkeys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.passkeys_id_seq OWNED BY public.passkeys.id;



-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) with time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO builderad_owner;


-- Name: platform_maintenance; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.platform_maintenance (
    id smallint NOT NULL,
    revision bigint DEFAULT 0 NOT NULL,
    active boolean DEFAULT false NOT NULL,
    message_ar text DEFAULT 'نجري تحسينات على المنصة. يرجى المحاولة بعد قليل.'::text NOT NULL,
    message_en text DEFAULT 'We are improving the platform. Please try again shortly.'::text NOT NULL,
    retry_after integer DEFAULT 60 NOT NULL,
    changed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT platform_maintenance_id_check CHECK ((id = 1)),
    CONSTRAINT platform_maintenance_message_ar_check CHECK (((length(message_ar) >= 1) AND (length(message_ar) <= 500))),
    CONSTRAINT platform_maintenance_message_en_check CHECK (((length(message_en) >= 1) AND (length(message_en) <= 500))),
    CONSTRAINT platform_maintenance_retry_after_check CHECK (((retry_after >= 30) AND (retry_after <= 3600))),
    CONSTRAINT platform_maintenance_revision_check CHECK ((revision >= 0))
);


ALTER TABLE public.platform_maintenance OWNER TO builderad_owner;


-- Name: platform_maintenance_commands; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.platform_maintenance_commands (
    command_id uuid NOT NULL,
    actor_user_id bigint,
    actor_generation bigint,
    fingerprint text NOT NULL,
    previous_revision bigint NOT NULL,
    resulting_revision bigint NOT NULL,
    reason text NOT NULL,
    result jsonb NOT NULL,
    occurred_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT platform_maintenance_commands_fingerprint_check CHECK ((length(fingerprint) = 64)),
    CONSTRAINT platform_maintenance_commands_reason_check CHECK (((length(reason) >= 1) AND (length(reason) <= 500)))
);


ALTER TABLE public.platform_maintenance_commands OWNER TO builderad_owner;


-- Name: principal_lifecycles; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.principal_lifecycles (
    user_id bigint NOT NULL,
    status character varying(16) NOT NULL,
    generation bigint DEFAULT '0'::bigint NOT NULL,
    cause character varying(64),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    principal_class character varying(16) DEFAULT 'advertiser'::character varying NOT NULL,
    runtime_lock_marker boolean DEFAULT false NOT NULL,
    CONSTRAINT principal_lifecycles_class_check CHECK (((principal_class)::text = ANY ((ARRAY['advertiser'::character varying, 'staff'::character varying])::text[]))),
    CONSTRAINT principal_lifecycles_current_generation_check CHECK ((((status)::text <> 'current'::text) OR (generation > 0))),
    CONSTRAINT principal_lifecycles_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'current'::character varying, 'disabled'::character varying])::text[])))
);


ALTER TABLE public.principal_lifecycles OWNER TO builderad_owner;


-- Name: provider_access; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.provider_access (
    source_id uuid NOT NULL,
    provider text NOT NULL,
    resource character varying(180) NOT NULL,
    operation character varying(80) NOT NULL,
    purpose character varying(128) NOT NULL,
    version bigint NOT NULL,
    local_state text NOT NULL,
    native_state character varying(80) NOT NULL,
    encrypted_credentials text,
    credential_expires_at timestamp with time zone,
    verification jsonb NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT provider_access_check CHECK ((((local_state = 'REVOKED'::text) AND (encrypted_credentials IS NULL)) OR ((local_state = 'CREDENTIAL_STORED'::text) AND (length(encrypted_credentials) > 0)))),
    CONSTRAINT provider_access_local_state_check CHECK ((local_state = ANY (ARRAY['CREDENTIAL_STORED'::text, 'REVOKED'::text]))),
    CONSTRAINT provider_access_native_state_check CHECK (((native_state)::text ~ '^[A-Z][A-Z0-9_]{0,79}$'::text)),
    CONSTRAINT provider_access_provider_check CHECK ((provider = ANY (ARRAY['meta'::text, 'google'::text, 'snapchat'::text, 'tiktok'::text, 'x'::text]))),
    CONSTRAINT provider_access_verification_check CHECK ((jsonb_typeof(verification) = 'object'::text)),
    CONSTRAINT provider_access_version_check CHECK ((version > 0))
);

ALTER TABLE ONLY public.provider_access FORCE ROW LEVEL SECURITY;


ALTER TABLE public.provider_access OWNER TO builderad_owner;


-- Name: provider_access_events; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.provider_access_events (
    namespace text NOT NULL,
    command_id uuid NOT NULL,
    actor_user_id bigint NOT NULL,
    actor_generation bigint NOT NULL,
    effect text NOT NULL,
    prior_version bigint,
    version bigint NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT provider_access_events_actor_generation_check CHECK ((actor_generation > 0)),
    CONSTRAINT provider_access_events_effect_check CHECK ((effect = ANY (ARRAY['connect'::text, 'reconnect'::text, 'revoke'::text])))
);

ALTER TABLE ONLY public.provider_access_events FORCE ROW LEVEL SECURITY;


ALTER TABLE public.provider_access_events OWNER TO builderad_owner;


-- Name: provider_access_receipts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.provider_access_receipts (
    namespace text NOT NULL,
    command_id uuid NOT NULL,
    source_id uuid NOT NULL,
    provider text NOT NULL,
    resource character varying(180) NOT NULL,
    operation character varying(80) NOT NULL,
    purpose character varying(128) NOT NULL,
    fingerprint text NOT NULL,
    result jsonb NOT NULL,
    CONSTRAINT provider_access_receipts_fingerprint_check CHECK ((fingerprint ~ '^[0-9a-f]{64}$'::text))
);

ALTER TABLE ONLY public.provider_access_receipts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.provider_access_receipts OWNER TO builderad_owner;


-- Name: provider_observation_entries; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.provider_observation_entries (
    namespace text NOT NULL,
    generation bigint NOT NULL,
    identity text NOT NULL,
    content_hash text NOT NULL,
    observation jsonb NOT NULL,
    prior_generation bigint,
    relation text NOT NULL,
    CONSTRAINT provider_observation_entries_content_hash_check CHECK ((content_hash ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT provider_observation_entries_relation_check CHECK ((relation = ANY (ARRAY['FIRST_OBSERVATION'::text, 'IDENTICAL_REREAD'::text, 'RESTATEMENT'::text])))
);

ALTER TABLE ONLY public.provider_observation_entries FORCE ROW LEVEL SECURITY;


ALTER TABLE public.provider_observation_entries OWNER TO builderad_owner;


-- Name: provider_observation_runs; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.provider_observation_runs (
    namespace text NOT NULL,
    generation bigint NOT NULL,
    command_id uuid NOT NULL,
    fingerprint text NOT NULL,
    coverage text NOT NULL,
    provenance jsonb NOT NULL,
    failure text,
    progress_advanced boolean NOT NULL,
    actor_user_id bigint NOT NULL,
    actor_generation bigint NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT provider_observation_runs_coverage_check CHECK ((coverage = ANY (ARRAY['COMPLETE'::text, 'PARTIAL'::text, 'FAILED'::text, 'UNKNOWN'::text, 'PENDING'::text]))),
    CONSTRAINT provider_observation_runs_provenance_check CHECK ((jsonb_typeof(provenance) = 'object'::text))
);

ALTER TABLE ONLY public.provider_observation_runs FORCE ROW LEVEL SECURITY;


ALTER TABLE public.provider_observation_runs OWNER TO builderad_owner;


-- Name: provider_observation_streams; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.provider_observation_streams (
    namespace text NOT NULL,
    source_id uuid NOT NULL,
    provider text NOT NULL,
    resource character varying(180) NOT NULL,
    operation character varying(80) NOT NULL,
    purpose character varying(128) NOT NULL,
    stream character varying(128) NOT NULL,
    generation bigint NOT NULL,
    access_version bigint NOT NULL,
    progress_generation bigint,
    encrypted_progress text,
    CONSTRAINT provider_observation_streams_access_version_check CHECK ((access_version > 0)),
    CONSTRAINT provider_observation_streams_check CHECK ((((progress_generation IS NULL) AND (encrypted_progress IS NULL)) OR ((progress_generation > 0) AND (progress_generation <= generation) AND (length(encrypted_progress) > 0)))),
    CONSTRAINT provider_observation_streams_generation_check CHECK ((generation > 0)),
    CONSTRAINT provider_observation_streams_operation_check CHECK (((operation)::text = 'report.read'::text)),
    CONSTRAINT provider_observation_streams_provider_check CHECK ((provider = ANY (ARRAY['meta'::text, 'google'::text, 'snapchat'::text, 'tiktok'::text, 'x'::text]))),
    CONSTRAINT provider_observation_streams_stream_check CHECK (((stream)::text ~ '^[a-zA-Z0-9_.:-]{1,128}$'::text))
);

ALTER TABLE ONLY public.provider_observation_streams FORCE ROW LEVEL SECURITY;


ALTER TABLE public.provider_observation_streams OWNER TO builderad_owner;


-- Name: registration_email_proofs; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.registration_email_proofs (
    attempt_id uuid NOT NULL,
    lineage_id uuid,
    epoch bigint,
    credential_hash character(64),
    email text,
    code_digest character(64),
    attempts smallint DEFAULT 0 NOT NULL,
    resend_at timestamp with time zone,
    expires_at timestamp with time zone NOT NULL,
    verified_at timestamp with time zone,
    user_id bigint,
    CONSTRAINT registration_email_proofs_attempts_check CHECK (((attempts >= 0) AND (attempts <= 5))),
    CONSTRAINT registration_email_proofs_check CHECK ((((user_id IS NULL) AND (lineage_id IS NOT NULL) AND (epoch IS NOT NULL) AND (credential_hash IS NOT NULL) AND (email IS NOT NULL) AND (resend_at IS NOT NULL) AND ((verified_at IS NULL) OR (code_digest IS NULL))) OR ((user_id IS NOT NULL) AND (lineage_id IS NULL) AND (epoch IS NULL) AND (credential_hash IS NULL) AND (email IS NULL) AND (resend_at IS NULL) AND (verified_at IS NULL) AND (code_digest IS NULL))))
);


ALTER TABLE public.registration_email_proofs OWNER TO builderad_owner;


-- Name: resource_right_fences; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.resource_right_fences (
    fence_id uuid NOT NULL,
    kind text NOT NULL,
    source_id uuid,
    boundary_id uuid,
    rights_epoch bigint NOT NULL,
    adoption_state text NOT NULL,
    data_use_scope_id uuid,
    CONSTRAINT resource_right_fences_adoption_state_check CHECK ((adoption_state = ANY (ARRAY['native_only'::text, 'v2_adopted'::text]))),
    CONSTRAINT resource_right_fences_check CHECK ((((kind = 'source'::text) AND (source_id IS NOT NULL) AND (boundary_id IS NULL) AND (data_use_scope_id IS NULL)) OR ((kind = 'client_boundary'::text) AND (source_id IS NULL) AND (boundary_id IS NOT NULL) AND (data_use_scope_id IS NULL)) OR ((kind = 'data_use'::text) AND (source_id IS NULL) AND (boundary_id IS NULL) AND (data_use_scope_id IS NOT NULL)))),
    CONSTRAINT resource_right_fences_fence_id_check CHECK ((uuid_extract_version(fence_id) = 7)),
    CONSTRAINT resource_right_fences_kind_check CHECK ((kind = ANY (ARRAY['source'::text, 'client_boundary'::text, 'data_use'::text]))),
    CONSTRAINT resource_right_fences_rights_epoch_check CHECK ((rights_epoch >= 0))
);

ALTER TABLE ONLY public.resource_right_fences FORCE ROW LEVEL SECURITY;


ALTER TABLE public.resource_right_fences OWNER TO builderad_owner;


-- Name: security_action_grants; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.security_action_grants (
    id bigint NOT NULL,
    subject_user_id bigint NOT NULL,
    granted_by bigint,
    source character varying(32) DEFAULT 'human'::character varying NOT NULL,
    action character varying(64) NOT NULL,
    scope_kind character varying(16) NOT NULL,
    subject_generation bigint NOT NULL,
    reason character varying(255) NOT NULL,
    status character varying(16) NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    expires_at timestamp(0) with time zone,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(6) with time zone,
    updated_at timestamp(0) with time zone,
    runtime_lock_marker boolean DEFAULT false NOT NULL,
    commercial_target_id uuid,
    commercial_version_id uuid,
    source_target_id uuid,
    source_resource character varying(180),
    source_operation character varying(80),
    source_purpose character varying(128),
    business_id bigint,
    boundary_id uuid,
    data_use_scope_id uuid,
    qualification_id uuid,
    parent_grant_id bigint,
    delegable boolean DEFAULT false NOT NULL,
    delegation_realm_kind text,
    CONSTRAINT security_action_grants_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'revoked'::character varying])::text[]))),
    CONSTRAINT source_grant_shape CHECK (((((scope_kind)::text <> 'source'::text) AND (source_target_id IS NULL) AND (source_resource IS NULL) AND (source_operation IS NULL) AND (source_purpose IS NULL)) OR (((scope_kind)::text = 'source'::text) AND (uuid_extract_version(source_target_id) = 7) AND ((action)::text = ANY ((ARRAY['source.register'::character varying, 'source.manage'::character varying, 'source.read'::character varying, 'source.audit.recover'::character varying, 'provider.connect'::character varying, 'provider.read'::character varying, 'provider.revoke'::character varying])::text[])) AND (source_resource IS NOT NULL) AND (source_operation IS NOT NULL) AND (source_purpose IS NOT NULL) AND (length(TRIM(BOTH FROM source_resource)) > 0) AND (length(TRIM(BOTH FROM source_operation)) > 0) AND (length(TRIM(BOTH FROM source_purpose)) > 0)))),
    CONSTRAINT security_action_grants_native_contract_1 CHECK (scope_kind IN ('platform','commercial','business','source','client_data','data_use')),
    CONSTRAINT security_action_grants_native_contract_2 CHECK (delegation_realm_kind IS NULL OR delegation_realm_kind='direct'),
    CONSTRAINT security_action_grants_native_contract_3 CHECK ((scope_kind='platform' AND business_id IS NULL AND commercial_target_id IS NULL AND commercial_version_id IS NULL AND boundary_id IS NULL AND data_use_scope_id IS NULL AND qualification_id IS NULL AND parent_grant_id IS NULL AND delegation_realm_kind IS NULL) OR (scope_kind='commercial' AND commercial_target_id IS NOT NULL AND source='human' AND source_target_id IS NULL AND boundary_id IS NULL AND data_use_scope_id IS NULL AND qualification_id IS NULL AND parent_grant_id IS NULL AND delegation_realm_kind IS NULL) OR (scope_kind IN ('business','client_data','data_use') AND commercial_target_id IS NULL AND commercial_version_id IS NULL AND source_target_id IS NULL AND source_resource IS NULL AND source_operation IS NULL AND source_purpose IS NULL AND qualification_id IS NOT NULL AND delegation_realm_kind='direct' AND ((scope_kind='business' AND business_id IS NOT NULL AND boundary_id IS NULL AND data_use_scope_id IS NULL) OR (scope_kind='client_data' AND business_id IS NULL AND boundary_id IS NOT NULL AND data_use_scope_id IS NULL) OR (scope_kind='data_use' AND business_id IS NULL AND boundary_id IS NULL AND data_use_scope_id IS NOT NULL))) OR (scope_kind='source' AND business_id IS NOT NULL AND commercial_target_id IS NULL AND commercial_version_id IS NULL AND boundary_id IS NULL AND data_use_scope_id IS NULL AND source_target_id IS NOT NULL AND source='source_owner_request' AND granted_by=subject_user_id AND qualification_id IS NOT NULL AND parent_grant_id IS NULL AND NOT delegable AND delegation_realm_kind IS NULL)),
    CONSTRAINT security_action_grants_native_contract_4 CHECK ((scope_kind='platform' AND ((source='founder_bootstrap' AND granted_by IS NULL) OR (source='human' AND granted_by IS NOT NULL AND granted_by<>subject_user_id))) OR (scope_kind='commercial' AND source='human' AND granted_by IS NOT NULL AND granted_by<>subject_user_id) OR (scope_kind='source' AND source='source_owner_request' AND granted_by=subject_user_id) OR (scope_kind IN ('business','client_data','data_use') AND ((source='business_draft_creation' AND granted_by IS NULL AND parent_grant_id IS NULL AND NOT delegable) OR (source='qualified_policy' AND granted_by IS NULL AND parent_grant_id IS NULL) OR (source='qualified_delegation' AND granted_by IS NOT NULL AND granted_by<>subject_user_id AND parent_grant_id IS NOT NULL))))
);

ALTER TABLE ONLY public.security_action_grants FORCE ROW LEVEL SECURITY;


ALTER TABLE public.security_action_grants OWNER TO builderad_owner;


-- Name: security_action_grants_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.security_action_grants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.security_action_grants_id_seq OWNER TO builderad_owner;


-- Name: security_action_grants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.security_action_grants_id_seq OWNED BY public.security_action_grants.id;



-- Name: security_audit_events; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.security_audit_events (
    id bigint NOT NULL,
    actor_user_id bigint,
    subject_user_id bigint NOT NULL,
    event character varying(64) NOT NULL,
    reason character varying(128) NOT NULL,
    prior_status character varying(16),
    new_status character varying(16) NOT NULL,
    prior_generation bigint,
    new_generation bigint NOT NULL,
    occurred_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.security_audit_events OWNER TO builderad_owner;


-- Name: security_audit_events_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.security_audit_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.security_audit_events_id_seq OWNER TO builderad_owner;


-- Name: security_audit_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.security_audit_events_id_seq OWNED BY public.security_audit_events.id;



-- Name: security_commercial_audit_custody; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.security_commercial_audit_custody (
    obligation_id uuid NOT NULL,
    fingerprint character(64) NOT NULL,
    envelope jsonb NOT NULL,
    acknowledgment_id uuid DEFAULT uuidv7() NOT NULL,
    accepted_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT security_commercial_audit_custody_fingerprint_check CHECK ((fingerprint ~ '^[a-f0-9]{64}$'::text))
);

ALTER TABLE ONLY public.security_commercial_audit_custody FORCE ROW LEVEL SECURITY;


ALTER TABLE public.security_commercial_audit_custody OWNER TO builderad_owner;


-- Name: security_commercial_mandate_executions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.security_commercial_mandate_executions (
    mandate_id uuid NOT NULL,
    fingerprint character(64) NOT NULL,
    grant_id bigint NOT NULL,
    executed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);

ALTER TABLE ONLY public.security_commercial_mandate_executions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.security_commercial_mandate_executions OWNER TO builderad_owner;


-- Name: security_commercial_market_approvals; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.security_commercial_market_approvals (
    id uuid NOT NULL,
    target_id uuid NOT NULL,
    founder_id bigint NOT NULL,
    founder_generation bigint CONSTRAINT security_commercial_market_approval_founder_generation_not_null NOT NULL,
    administrator_id bigint NOT NULL,
    administrator_generation bigint CONSTRAINT security_commercial_market_ap_administrator_generation_not_null NOT NULL,
    label character varying(120) NOT NULL,
    reason character varying(120) NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    fingerprint character(64) NOT NULL,
    grant_id bigint NOT NULL,
    approved_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT security_commercial_market_approvals_check CHECK ((founder_id <> administrator_id)),
    CONSTRAINT security_commercial_market_approvals_id_check CHECK ((uuid_extract_version(id) = 7)),
    CONSTRAINT security_commercial_market_approvals_target_id_check CHECK ((uuid_extract_version(target_id) = 7))
);

ALTER TABLE ONLY public.security_commercial_market_approvals FORCE ROW LEVEL SECURITY;


ALTER TABLE public.security_commercial_market_approvals OWNER TO builderad_owner;


-- Name: security_sessions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.security_sessions (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    session_key_hash character(64) NOT NULL,
    lifecycle_generation bigint NOT NULL,
    auth_method character varying(32) NOT NULL,
    client_description character varying(255),
    authenticated_at timestamp(0) with time zone NOT NULL,
    last_seen_at timestamp(0) with time zone NOT NULL,
    idle_expires_at timestamp(0) with time zone NOT NULL,
    absolute_expires_at timestamp(0) with time zone NOT NULL,
    revoked_at timestamp(0) with time zone,
    runtime_lock_marker boolean DEFAULT false NOT NULL
);


ALTER TABLE public.security_sessions OWNER TO builderad_owner;


-- Name: security_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.security_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.security_sessions_id_seq OWNER TO builderad_owner;


-- Name: security_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.security_sessions_id_seq OWNED BY public.security_sessions.id;



-- Name: security_source_audit_custody; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.security_source_audit_custody (
    obligation_id uuid NOT NULL,
    acknowledgment_id uuid DEFAULT uuidv7() NOT NULL,
    fingerprint text NOT NULL,
    envelope jsonb NOT NULL,
    accepted_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT security_source_audit_custody_fingerprint_check CHECK ((fingerprint ~ '^[0-9a-f]{64}$'::text))
);

ALTER TABLE ONLY public.security_source_audit_custody FORCE ROW LEVEL SECURITY;


ALTER TABLE public.security_source_audit_custody OWNER TO builderad_owner;


-- Name: service_action_grants; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.service_action_grants (
    id bigint NOT NULL,
    service_identity_id bigint NOT NULL,
    business_id bigint NOT NULL,
    action character varying(64) NOT NULL,
    granted_by bigint NOT NULL,
    reason character varying(128) NOT NULL,
    status character varying(16) NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    expires_at timestamp(0) with time zone,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT service_action_grants_action_check CHECK (((action)::text = 'business.metadata.read'::text)),
    CONSTRAINT service_action_grants_status_check CHECK (((((status)::text = 'active'::text) AND (revoked_at IS NULL)) OR (((status)::text = 'revoked'::text) AND (revoked_at IS NOT NULL))))
);


ALTER TABLE public.service_action_grants OWNER TO builderad_owner;


-- Name: service_action_grants_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.service_action_grants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.service_action_grants_id_seq OWNER TO builderad_owner;


-- Name: service_action_grants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.service_action_grants_id_seq OWNED BY public.service_action_grants.id;



-- Name: service_audit_events; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.service_audit_events (
    id bigint NOT NULL,
    actor_user_id bigint,
    service_identity_id bigint NOT NULL,
    business_id bigint,
    event character varying(64) NOT NULL,
    reason character varying(128) NOT NULL,
    prior_status character varying(16),
    new_status character varying(16) NOT NULL,
    prior_version bigint,
    new_version bigint NOT NULL,
    occurred_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.service_audit_events OWNER TO builderad_owner;


-- Name: service_audit_events_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.service_audit_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.service_audit_events_id_seq OWNER TO builderad_owner;


-- Name: service_audit_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.service_audit_events_id_seq OWNED BY public.service_audit_events.id;



-- Name: service_identities; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.service_identities (
    id bigint NOT NULL,
    public_id uuid DEFAULT uuidv7() NOT NULL,
    name character varying(120) NOT NULL,
    status character varying(16) NOT NULL,
    credential_hash character(64) NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    created_by bigint NOT NULL,
    revoked_by bigint,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    runtime_lock_marker boolean DEFAULT false NOT NULL,
    CONSTRAINT service_identities_status_check CHECK (((((status)::text = 'enabled'::text) AND (revoked_at IS NULL)) OR (((status)::text = 'revoked'::text) AND (revoked_at IS NOT NULL))))
);


ALTER TABLE public.service_identities OWNER TO builderad_owner;


-- Name: service_identities_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.service_identities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.service_identities_id_seq OWNER TO builderad_owner;


-- Name: service_identities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.service_identities_id_seq OWNED BY public.service_identities.id;



-- Name: service_business_scopes; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.service_business_scopes (
    service_identity_id bigint NOT NULL,
    business_id bigint NOT NULL,
    authorized_by bigint NOT NULL,
    purpose character varying(128) NOT NULL,
    status character varying(16) NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    revoked_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT service_business_scopes_status_check CHECK (((((status)::text = 'active'::text) AND (revoked_at IS NULL)) OR (((status)::text = 'revoked'::text) AND (revoked_at IS NOT NULL))))
);


ALTER TABLE public.service_business_scopes OWNER TO builderad_owner;


-- Name: snapchat_read_attempts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.snapchat_read_attempts (
    namespace text NOT NULL,
    command_id uuid NOT NULL,
    generation bigint NOT NULL,
    request_fingerprint text NOT NULL,
    result_fingerprint text NOT NULL,
    encrypted_result text NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT snapchat_read_attempts_encrypted_result_check CHECK (((length(encrypted_result) >= 1) AND (length(encrypted_result) <= 65536))),
    CONSTRAINT snapchat_read_attempts_request_fingerprint_check CHECK ((request_fingerprint ~ '^[a-f0-9]{64}$'::text)),
    CONSTRAINT snapchat_read_attempts_result_fingerprint_check CHECK ((result_fingerprint ~ '^[a-f0-9]{64}$'::text))
);

ALTER TABLE ONLY public.snapchat_read_attempts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.snapchat_read_attempts OWNER TO builderad_owner;


-- Name: snapchat_renewal_failures; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.snapchat_renewal_failures (
    source_id uuid NOT NULL,
    resource character varying(180) NOT NULL,
    operation character varying(80) NOT NULL,
    purpose character varying(128) NOT NULL,
    access_version bigint NOT NULL,
    command_id uuid NOT NULL,
    failure text NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT snapchat_renewal_failures_access_version_check CHECK ((access_version > 0)),
    CONSTRAINT snapchat_renewal_failures_failure_check CHECK ((failure ~ '^snapchat_[a-z_]{1,65}$'::text)),
    CONSTRAINT snapchat_renewal_failures_operation_check CHECK (((operation)::text = 'report.read'::text)),
    CONSTRAINT snapchat_renewal_failures_purpose_check CHECK (((purpose)::text = 'advertising_analysis'::text))
);

ALTER TABLE ONLY public.snapchat_renewal_failures FORCE ROW LEVEL SECURITY;


ALTER TABLE public.snapchat_renewal_failures OWNER TO builderad_owner;


-- Name: source_audit_delivery; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.source_audit_delivery (
    obligation_id uuid NOT NULL,
    state text DEFAULT 'PENDING'::text NOT NULL,
    acknowledgment_id uuid,
    attempts bigint DEFAULT 0 NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT source_audit_delivery_check CHECK (((state = 'ACKNOWLEDGED'::text) = (acknowledgment_id IS NOT NULL))),
    CONSTRAINT source_audit_delivery_state_check CHECK ((state = ANY (ARRAY['PENDING'::text, 'FAILED'::text, 'ACKNOWLEDGED'::text])))
);

ALTER TABLE ONLY public.source_audit_delivery FORCE ROW LEVEL SECURITY;


ALTER TABLE public.source_audit_delivery OWNER TO builderad_owner;


-- Name: source_audit_obligations; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.source_audit_obligations (
    id uuid DEFAULT uuidv7() NOT NULL,
    command_id uuid NOT NULL,
    source_id uuid NOT NULL,
    envelope jsonb NOT NULL,
    fingerprint text NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT source_audit_obligations_fingerprint_check CHECK ((fingerprint ~ '^[0-9a-f]{64}$'::text))
);

ALTER TABLE ONLY public.source_audit_obligations FORCE ROW LEVEL SECURITY;


ALTER TABLE public.source_audit_obligations OWNER TO builderad_owner;


-- Name: source_capabilities; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.source_capabilities (
    source_id uuid NOT NULL,
    resource character varying(180) NOT NULL,
    operation character varying(80) NOT NULL,
    purpose character varying(128) NOT NULL,
    declared_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT source_capabilities_operation_check CHECK ((length(TRIM(BOTH FROM operation)) > 0)),
    CONSTRAINT source_capabilities_purpose_check CHECK ((length(TRIM(BOTH FROM purpose)) > 0)),
    CONSTRAINT source_capabilities_resource_check CHECK ((length(TRIM(BOTH FROM resource)) > 0))
);

ALTER TABLE ONLY public.source_capabilities FORCE ROW LEVEL SECURITY;


ALTER TABLE public.source_capabilities OWNER TO builderad_owner;


-- Name: source_command_receipts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.source_command_receipts (
    command_id uuid NOT NULL,
    source_id uuid NOT NULL,
    effect text NOT NULL,
    resource character varying(180) NOT NULL,
    operation character varying(80) NOT NULL,
    purpose character varying(128) NOT NULL,
    fingerprint text NOT NULL,
    result jsonb NOT NULL,
    actor_user_id bigint NOT NULL,
    actor_generation bigint NOT NULL,
    decided_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT source_command_receipts_fingerprint_check CHECK ((fingerprint ~ '^[0-9a-f]{64}$'::text))
);

ALTER TABLE ONLY public.source_command_receipts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.source_command_receipts OWNER TO builderad_owner;


-- Name: source_registry; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.source_registry (
    id uuid NOT NULL,
    family character varying(40) NOT NULL,
    namespace character varying(80) NOT NULL,
    declared_external_id character varying(180) NOT NULL,
    label character varying(120) NOT NULL,
    version bigint NOT NULL,
    status text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    scope_lock_marker boolean DEFAULT false NOT NULL,
    custody_kind text DEFAULT 'business_native'::text NOT NULL,
    business_id bigint,
    CONSTRAINT source_registry_declared_external_id_check CHECK (((length(TRIM(BOTH FROM declared_external_id)) > 0) AND ((declared_external_id)::text = TRIM(BOTH FROM declared_external_id)))),
    CONSTRAINT source_registry_family_check CHECK (((family)::text ~ '^[a-z][a-z0-9_]{0,39}$'::text)),
    CONSTRAINT source_registry_id_check CHECK ((uuid_extract_version(id) = 7)),
    CONSTRAINT source_registry_label_check CHECK ((length(TRIM(BOTH FROM label)) > 0)),
    CONSTRAINT source_registry_namespace_check CHECK (((length(TRIM(BOTH FROM namespace)) > 0) AND ((namespace)::text = TRIM(BOTH FROM namespace)))),
    CONSTRAINT source_registry_status_check CHECK ((status = ANY (ARRAY['UNVERIFIED'::text, 'REVOKED'::text]))),
    CONSTRAINT source_registry_version_check CHECK ((version > 0)),
    CONSTRAINT source_registry_native_contract_1 CHECK (custody_kind IN ('business_native','platform_market')),
    CONSTRAINT source_registry_native_contract_2 CHECK ((custody_kind='business_native' AND business_id IS NOT NULL) OR (custody_kind='platform_market' AND business_id IS NULL))
);

ALTER TABLE ONLY public.source_registry FORCE ROW LEVEL SECURITY;


ALTER TABLE public.source_registry OWNER TO builderad_owner;


-- Name: source_versions; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.source_versions (
    source_id uuid NOT NULL,
    version bigint NOT NULL,
    prior_version bigint,
    command_id uuid NOT NULL,
    snapshot jsonb NOT NULL,
    CONSTRAINT source_versions_check CHECK (((version > 0) AND (NOT (prior_version IS DISTINCT FROM NULLIF((version - 1), 0)))))
);

ALTER TABLE ONLY public.source_versions FORCE ROW LEVEL SECURITY;


ALTER TABLE public.source_versions OWNER TO builderad_owner;


-- Name: tiktok_read_attempts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.tiktok_read_attempts (
    namespace text NOT NULL,
    command_id uuid NOT NULL,
    generation bigint NOT NULL,
    request_fingerprint text NOT NULL,
    result_fingerprint text NOT NULL,
    encrypted_result text NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT tiktok_read_attempts_encrypted_result_check CHECK (((length(encrypted_result) >= 1) AND (length(encrypted_result) <= 65536))),
    CONSTRAINT tiktok_read_attempts_request_fingerprint_check CHECK ((request_fingerprint ~ '^[a-f0-9]{64}$'::text)),
    CONSTRAINT tiktok_read_attempts_result_fingerprint_check CHECK ((result_fingerprint ~ '^[a-f0-9]{64}$'::text))
);

ALTER TABLE ONLY public.tiktok_read_attempts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.tiktok_read_attempts OWNER TO builderad_owner;


-- Name: users; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    public_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) with time zone,
    password character varying(255) NOT NULL,
    two_factor_secret_encrypted text,
    two_factor_confirmed_at timestamp(0) with time zone,
    two_factor_recovery_codes_encrypted text,
    remember_token character varying(100),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    scope_lock_marker boolean DEFAULT false NOT NULL
);


ALTER TABLE public.users OWNER TO builderad_owner;


-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: builderad_owner
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO builderad_owner;


-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: builderad_owner
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;



-- Name: x_read_attempts; Type: TABLE; Schema: public; Owner: builderad_owner
--

CREATE TABLE public.x_read_attempts (
    namespace text NOT NULL,
    command_id uuid NOT NULL,
    generation bigint NOT NULL,
    request_fingerprint text NOT NULL,
    result_fingerprint text NOT NULL,
    encrypted_result text NOT NULL,
    recorded_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT x_read_attempts_encrypted_result_check CHECK (((length(encrypted_result) >= 1) AND (length(encrypted_result) <= 65536))),
    CONSTRAINT x_read_attempts_request_fingerprint_check CHECK ((request_fingerprint ~ '^[a-f0-9]{64}$'::text)),
    CONSTRAINT x_read_attempts_result_fingerprint_check CHECK ((result_fingerprint ~ '^[a-f0-9]{64}$'::text))
);

ALTER TABLE ONLY public.x_read_attempts FORCE ROW LEVEL SECURITY;


ALTER TABLE public.x_read_attempts OWNER TO builderad_owner;
