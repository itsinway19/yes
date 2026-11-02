-- BF03-I01: private assertions, never provider facts or analytical findings.
CREATE TABLE inquiry_evidence_enrollments (
 qualification_id uuid PRIMARY KEY REFERENCES authority_qualification_receipts(qualification_id) ON DELETE RESTRICT,
 business_id bigint NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
 actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 generation bigint NOT NULL,
 basis_qualification_id uuid NOT NULL REFERENCES authority_qualification_receipts(qualification_id) ON DELETE RESTRICT,
 basis_revision bigint NOT NULL CHECK(basis_revision>0),
 policy_version integer NOT NULL CHECK(policy_version=1),
 status text NOT NULL CHECK(status IN('active','withdrawn')),
 created_at timestamptz NOT NULL
);
CREATE INDEX inquiry_enrollment_actor ON inquiry_evidence_enrollments(business_id,actor_user_id,generation,created_at);
CREATE TABLE inquiry_evidence_heads (
 declaration_id uuid PRIMARY KEY CHECK(uuid_extract_version(declaration_id)=7),
 business_id bigint NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
 issuer_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 revision bigint NOT NULL CHECK(revision>0),
 created_at timestamptz NOT NULL
);
CREATE INDEX inquiry_evidence_scope ON inquiry_evidence_heads(business_id,issuer_user_id,declaration_id);
CREATE TABLE inquiry_evidence_revisions (
 declaration_id uuid NOT NULL REFERENCES inquiry_evidence_heads(declaration_id) ON DELETE RESTRICT,
 revision bigint NOT NULL CHECK(revision>0),
 actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 qualification_id uuid NOT NULL REFERENCES inquiry_evidence_enrollments(qualification_id) ON DELETE RESTRICT,
 command_id uuid NOT NULL UNIQUE,
 state text NOT NULL CHECK(state IN('source_asserted','withdrawn')),
 reason text NOT NULL CHECK(reason IN('initial','correction','withdrawal')),
 value jsonb,
 recorded_at timestamptz NOT NULL,
 PRIMARY KEY(declaration_id,revision),
 CHECK((state='source_asserted' AND value IS NOT NULL AND jsonb_typeof(value)='object') OR(state='withdrawn' AND value IS NULL))
);
