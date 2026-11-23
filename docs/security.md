# NexaMind AI — Security Architecture & Controls

NexaMind AI is designed for regulated enterprise environments using defense-in-depth across identity, multi-tenancy, document ingestion, Generative AI safety, autonomous agent execution, and cloud CI/CD.

---

## 1. Authentication & Session Security
- **Zero-`localStorage` SPA Auth:** The React frontend authenticates with Laravel Sanctum using `HttpOnly`, `SameSite`, `Secure` (production) session cookies and CSRF tokens (`X-XSRF-TOKEN`). No bearer token is ever persisted in browser `localStorage`.
- **Rate Limiting:** Auth endpoints (`/auth/login`, `/auth/register`) are throttled at 20 requests/min per IP; authenticated APIs at 120 requests/min per user.

## 2. Multi-Tenant Isolation & Authorization
- **Mandatory Tenant Context (`ResolveTenantContext`):** Every tenant-scoped endpoint requires a valid `X-Tenant-ID` UUID matching an active `tenant_memberships` row for the authenticated user.
- **Model-Level Scoping (`BelongsToTenant`):** Applies a global Eloquent scope pinning queries to the resolved tenant ID.
- **RBAC + Pre-Retrieval Resource ACLs (`AuthorizedScopeResolver`):**
  - 5 system roles (`owner`, `admin`, `manager`, `member`, `viewer`) mapped to 11 least-privilege permissions.
  - Knowledge Base visibility (`tenant` vs `restricted`) and Document `access_policy` (`inherit` vs `restricted`) are resolved **before** SQL vector search runs.
  - **Strict Confidentiality Invariant:** Even an Organization `Owner` cannot view, download, or vector-retrieve a document with `access_policy = 'restricted'` unless explicitly granted a `document_permissions` record.
- **Verified By:** `tests/Security/CrossTenantIsolationTest.php`, `tests/Security/DocumentAndRetrievalAclSecurityTest.php`.

## 3. Document Upload & Storage Hardening
- **Private Blob Storage Only:** Azure Blob Storage is provisioned with `allowBlobPublicAccess: false`, `minimumTlsVersion: 'TLS1_2'`, and `supportsHttpsTrafficOnly: true`.
- **Short-Lived Authorized Downloads:** Files are never exposed publicly; downloads require ACL authorization and issue a 5-minute Service SAS URL forcing `Content-Disposition: attachment`.
- **Upload Security Pipeline (`UploadSecurityValidator`):**
  - Magic-byte MIME verification (blocks spoofed executables renamed to `.pdf`).
  - Per-tenant file size enforcement (default 20 MB).
  - Zip-bomb compression ratio, entry count, and path-traversal checks on `.docx` archives, plus XXE-safe XML extraction.
  - Rejected uploads record `security_events`.

## 4. Generative AI Safety (RAG & Prompt Injection)
- **Indirect Prompt Injection Defense (`PromptInjectionGuard` + `GroundedPromptBuilder`):**
  - Inspects user prompts and every retrieved chunk for jailbreak/override patterns (`ignore previous instructions`, `reveal secrets`, XML tag breakout).
  - Neutralizes closing XML fences and wraps all retrieved document text inside `<untrusted_document_evidence>` as untrusted data.
- **Hallucination & Fabricated Citation Prevention (`CitationVerifier`):**
  - Validates every returned citation identifier against the exact set of authorized chunk IDs retrieved for that specific request.
  - Strips any fabricated/unauthorized citation UUIDs and records `rag.fabricated_citation_blocked` (`high` severity).
  - Triggers **Safe Abstention** (zero citations, explicit insufficiency notice) when no authorized chunks meet the minimum similarity score (`0.08`).
- **Verified By:** `tests/Security/RagPromptInjectionAndQuotaSecurityTest.php`, `tests/Unit/CitationVerifierAndPromptGuardUnitTest.php`.

## 5. Controlled AI Agent & Approval Security Invariants
- **Hard Allowlisted `ToolRegistry`:** Only 5 safe enterprise tools exist (`search_knowledge_base`, `extract_document_clauses`, `draft_compliance_report`, `create_approval_request`, `publish_approved_report`).
  - **Zero SQL execution tool.**
  - **Zero OS/shell command execution tool.**
  - **Zero arbitrary outbound HTTP tool.**
- **Strict JSON Schema Validation (`JsonSchemaValidator`):** Rejects missing required parameters, invalid types/bounds, and unexpected arguments (`additionalProperties: false`) with HTTP `422 TOOL_SCHEMA_INVALID` + `security_events`.
- **Human Approval Gate (`PublishApprovedReportTool`):** Publishing a report verifies an existing `approval_requests` row in the **same tenant** with `status = 'approved'` and a non-null `decided_by`. Pending, rejected, nonexistent, or cross-tenant approval IDs are blocked with HTTP `403 UNAPPROVED_PUBLISH` (`critical` security event).
- **Verified By:** `tests/Security/AgentToolSecurityTest.php`, `tests/Feature/WorkflowApprovalIdempotencyAndPortfolioTest.php`.

## 6. Tamper-Evident Audit Logging
- Every state-changing action records an append-only row in `audit_logs` containing `previous_hash` and `record_hash = SHA-256(canonical_record + previous_hash)`.
- Any modification or deletion of historical audit entries breaks the chain and is immediately detected by `GET /api/v1/audit-logs/verify-chain` and Screen 17 (Security Audit Dashboard).

## 7. CI/CD Supply Chain & Keyless Cloud Auth
- **Zero Static Cloud Credentials:** GitHub Actions deploy and rollback workflows use **Microsoft Entra ID OIDC** (`id-token: write`, `azure/login@v2`) restricted to protected GitHub Environments (`azure-dev`) requiring manual reviewer approval.
- **Automated Security Scans on Every Push/PR:**
  - `composer audit` (PHP advisories)
  - `npm audit --omit=dev --audit-level=critical` (Frontend runtime advisories)
  - Trivy container scans (`aquasecurity/trivy-action@v0.36.0`) failing CI on any fixable `CRITICAL` OS or library vulnerability.
  - Strict Bicep linter (`bicepconfig.json`) blocking hardcoded secrets or secret outputs.
