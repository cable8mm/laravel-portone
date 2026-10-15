# ARCHITECTURE

## Purpose

- This document defines how the Package works internally: its components, who owns which responsibility, how information flows, and which invariants always hold.
- Identifiers FR-, EL-, SCR-, ERR-, AC-, FLOW- refer to PRODUCT_SPEC.md. Terms defined in PRODUCT_SPEC.md (Package, Application, Billable model, Developer, Payer, Payment ID, Customer ID, Customer record, Payment record) keep their meaning.
- Every behavior in PRODUCT_SPEC.md is assigned to exactly one component below. A behavior with no owner is a defect of this document.
- Where this document and PRODUCT_SPEC.md disagree, PRODUCT_SPEC.md wins and this document is wrong.

## Core Concepts

- **Billable reference**: the identity of one Billable model, consisting of the model kind and its key. Two Billable models of different kinds may share a key; the reference distinguishes them.
- **Scope**: every operation reachable from a Billable model is bound to that model's Billable reference. A Payment record outside that scope does not exist for the operation.
- **Customer record**: one per Billable reference. Holds the Customer ID.
- **Payment record**: owned by exactly one Billable reference. Lifecycle: `PENDING -> PAID -> PARTIALLY_CANCELLED -> CANCELLED`, with `PENDING -> EXPIRED` for an abandoned payment. Allowed transitions only:
  - PENDING -> PAID
  - PENDING -> EXPIRED
  - EXPIRED -> PAID
  - PAID -> PARTIALLY_CANCELLED
  - PAID -> CANCELLED
  - PARTIALLY_CANCELLED -> PARTIALLY_CANCELLED
  - PARTIALLY_CANCELLED -> CANCELLED
  - PENDING -> AMOUNT_MISMATCH_CANCELLED, PENDING -> AMOUNT_MISMATCH (paid at PortOne with a different total; FR-069, FR-070)
  - EXPIRED -> AMOUNT_MISMATCH_CANCELLED, EXPIRED -> AMOUNT_MISMATCH
  - CANCELLED, AMOUNT_MISMATCH_CANCELLED, and AMOUNT_MISMATCH are terminal for the Package. An AMOUNT_MISMATCH record is handled by the Developer outside the Package.
  - Deletion: only EXPIRED records, only through EL-035 (FR-063).
- **Exclusive access**: a period during which no other operation of the Package can read a given Payment record for a decision or write it. Provided by the Payment Ledger, requested by Payment Operations.
- **Precomputed outcome**: the complete set of absolute field values (status, cancelled_amount, paid_at, paid_amount) computed before any PortOne request that changes remote state. Written to the record as absolute values, never as increments.
- **Gateway result**: what the PortOne Gateway returns for a payment lookup: the PortOne payment status and, when paid, the total paid amount. For an expiry check only, it can also say that PortOne does not know the payment.
- **Stale PENDING record**: a PENDING record whose created_at is older than EL-033.
- **Verified webhook**: a webhook whose authenticity was established by the Webhook Verifier. The only kind of webhook the Package acts on.
- **External parties** (not components of the Package):
  - Application: owns authentication, the Billable models, the host error handling, and, after publishing, the checkout stub files.
  - Payer: acts only through the browser.
  - PortOne API: payment lookup and cancellation.
  - PortOne browser SDK: opens the PG payment window.
  - Event listeners: Application code that reacts to WebhookReceived.
  - Scheduler and queue worker: Application infrastructure that runs EL-031, model:prune, and EL-032.
- **Core versus stubs**: Core = CMP-01 through CMP-10, CMP-15, CMP-16. Stubs = CMP-11 through CMP-14 and CMP-17. The Core never depends on the Stubs. The Stubs reach the Core only through the Billable Facade and Configuration.

## System Flow

### SF-1 Prepare (FLOW-001 steps 1-2, FLOW-005 step 3)

1. Developer or Checkout Controller calls the Billable Facade (EL-004).
2. Facade passes the Billable reference, amount, order name to Payment Operations.
3. Payment Operations validates arguments.
4. In one atomic unit: Customer Registry ensures the Customer record, Payment Ledger creates the PENDING Payment record.
5. The Payment record is returned. No request is sent to PortOne.
6. The Payment record's checkoutParameters (EL-010) are read by the caller.

### SF-2 Complete (FLOW-001 steps 3-4, FLOW-005 step 5)

1. Facade passes the Billable reference and Payment ID to Payment Operations.
2. Payment Ledger finds the record inside the Billable reference's scope, otherwise ERR-003.
3. Payment Operations takes exclusive access and reads the record's current state.
4. A PAID, PARTIALLY_CANCELLED, or CANCELLED record is returned unchanged (FR-022). An AMOUNT_MISMATCH_CANCELLED or AMOUNT_MISMATCH record ends the sequence with ERR-007 built from the stored record, with no PortOne request (FR-071).
5. PortOne Gateway looks up the payment.
6. Payment Lifecycle decides: not paid -> ERR-006; paid with a different total -> the amount-mismatch sequence (SF-9); otherwise produces the Precomputed outcome (PAID, paid_at).
7. Payment Operations writes the Precomputed outcome through the Payment Ledger under the persistence retry rule (RD-11).
8. Exclusive access ends; the record is returned, or ERR-007 is raised (RD-31).

### SF-3 Refund (FLOW-002)

1. Facade passes the Billable reference, Payment ID, reason, amount to Payment Operations.
2. Payment Ledger finds the record in scope, otherwise ERR-003.
3. Payment Operations validates the reason (ERR-009).
4. Payment Operations takes exclusive access and reads the current state.
5. Payment Lifecycle checks the status (ERR-008) and the refund amount (ERR-010) and produces the Precomputed outcome.
6. PortOne Gateway sends the cancellation request.
7. Payment Operations writes the Precomputed outcome under the persistence retry rule (RD-11). The PortOne request is never repeated.
8. Exclusive access ends; the record is returned.

### SF-4 Webhook (FLOW-003)

1. PortOne sends a request to the webhook route owned by the Webhook Receiver.
2. Webhook Receiver checks that the webhook secret is configured (ERR-002).
3. Webhook Receiver passes the unmodified raw body, the three signature headers, and the secret to the Webhook Verifier.
4. Verification failure or undecodable body -> HTTP 400, nothing else happens.
5. Verification success -> Webhook Receiver dispatches exactly one WebhookReceived event, then responds HTTP 200 with an empty body.
6. No Customer record or Payment record is read or written at any point.

### SF-5 Checkout with stubs (FLOW-005)

1. Payer visits the checkout route; the Application authenticates; Checkout Controller `show` renders Checkout View.
2. Payer clicks the start-payment button; Checkout Script requests the `prepare` route.
3. Checkout Controller `prepare` runs SF-1 for the authenticated user with the price and name of the product from its product lookup and responds with the checkoutParameters.
4. Checkout Script calls the PortOne browser SDK; the Payer pays outside the Package.
5. On an SDK result without an error code, Checkout Script requests the `complete` route with the Payment ID.
6. Checkout Controller `complete` runs SF-2 for the authenticated user and responds with the status and, for PAID, the URL of the `completed` route for that Payment ID.
7. Checkout Script moves Checkout View to the Success or Error state; on Success it navigates the browser to that URL.
8. Checkout Controller `completed` finds the record through the Facade's scoped lookup (EL-008) and renders Completion View when it has been paid; otherwise it responds HTTP 404.

### SF-6 History and customer queries (FLOW-004, EL-001 to EL-003, EL-007, EL-008)

- Facade answers EL-001, EL-002, EL-003 from the Customer Registry only.
- Facade answers EL-007, EL-008 from the Payment Ledger only.
- Payment Operations is not involved.

### SF-7 Expire (FLOW-006 steps 1-5)

1. The Application's scheduler runs the Expiry Command; it reads EL-033 from Configuration and the current time.
2. Payment Ledger selects the stale PENDING records (RD-26).
3. The Expiry Command queues one Expiry Job per record, carrying the record's Billable reference and Payment ID. No PortOne request (FR-057).
4. The queue worker runs the Expiry Job; it passes the Billable reference and Payment ID to Payment Operations.
5. Payment Operations takes exclusive access and reads the record's current state. A missing or non-PENDING record ends the job with no change (FR-058).
6. PortOne Gateway looks up the payment in its expiry form (RD-28).
7. Payment Lifecycle decides: paid with the same total -> Precomputed outcome PAID; paid with a different total -> the amount-mismatch sequence (SF-9); a "not paid at the time of the check" result -> Precomputed outcome EXPIRED; anything else -> no change (FR-060 to FR-062).
8. Payment Operations writes the outcome through the Payment Ledger under the persistence retry rule (RD-11), or writes nothing.
9. Exclusive access ends. ConfigurationException and PortOne failures propagate out of the job unwrapped (ERR-016, RD-27). After SF-9, the job ends without an exception when PortOne accepted the cancellation, and fails with the PortOne exception of the cancellation otherwise (FR-072).

### SF-9 Amount mismatch (FLOW-001 step 5, FLOW-006 step 4a)

Runs inside the exclusive access of SF-2 or SF-7, after the lookup reported paid with a total that differs from the record's amount.

1. Payment Lifecycle produces two Precomputed outcomes before any cancellation: AMOUNT_MISMATCH_CANCELLED with paid_amount and cancelled_amount equal to the total paid amount (FR-069), and AMOUNT_MISMATCH with paid_amount equal to the total paid amount (FR-070).
2. Payment Operations reads EL-037 and asks the PortOne Gateway to cancel the payment with that reason and no amount (FR-068).
3. Accepted -> Payment Operations writes the AMOUNT_MISMATCH_CANCELLED outcome. Failed -> it writes the AMOUNT_MISMATCH outcome and keeps the PortOne exception. Both writes use the persistence retry rule (RD-11).
4. SF-2 raises ERR-007 after exclusive access ends; SF-7 ends as in its step 9.

### SF-8 Prune (FLOW-006 step 6)

1. The Application's scheduler runs model:prune for the Payment model.
2. The prunable definition of the Payment Ledger selects EXPIRED records whose updated_at is older than EL-034 (FR-063).
3. The prune run deletes the selected records. No other record is selectable (FR-064).

## Components

### CMP-01 Configuration

- Responsibilities:
  - Expose the values EL-015 to EL-018, EL-022, EL-023 under the key `portone` (FR-004).
  - Apply the default of EL-018 and the default base address rule of FR-005/FR-006.
  - Report a value as absent when it is unset or empty.
- Inputs: values supplied by the Application's environment.
- Outputs: read-only values to the Gateway (EL-015, EL-017), the Webhook Receiver (EL-016, EL-018), the Checkout Controller (EL-022, EL-023), and Package Registration (EL-018).
- Owns: the names, defaults, and absence rule of the values.
- Does not own: deciding what an absent value means. Each consumer decides at its own point of use (RD-15).

### CMP-02 Package Registration

- Responsibilities:
  - Make the Package usable with no manual registration step (FR-001).
  - Make the configuration, the persistent record definitions, and the stub files publishable under EL-019, EL-020, EL-024 (FR-002, FR-003, FR-040).
  - Register the webhook route at the path from Configuration, named `portone.webhook`, outside authentication and outside forgery-token protection, and only that route (FR-033, FR-034).
  - Never register the routes of CMP-14 (FR-041).
- Inputs: the Application's start-up; EL-018 from Configuration.
- Outputs: the webhook route bound to the Webhook Receiver; publishable file groups.
- Owns: which routes exist because of the Package; the exemption of the webhook route from authentication and forgery-token protection.
- Does not own: the content of published files after publishing; any request handling.

### CMP-03 Billable Facade

- Responsibilities:
  - Provide EL-001 to EL-008 on any Billable model (FR-007).
  - Bind every call to the calling model's Billable reference (Scope).
  - Delegate: EL-001, EL-002, EL-003 to the Customer Registry; EL-004, EL-005, EL-006 to Payment Operations; EL-007, EL-008 to the Payment Ledger.
- Inputs: calls from the Developer or from the Checkout Controller, each carrying the calling model's identity.
- Outputs: the results of the delegated calls, unmodified; exceptions pass through unmodified.
- Owns: the binding of a call to a Billable reference.
- Does not own: validation, state rules, persistence, or any PortOne contact.

### CMP-04 Customer Registry

- Responsibilities:
  - Persist Customer records (FR-012).
  - Generate Customer IDs.
  - Ensure at most one Customer record per Billable reference and a Customer ID that is unique across all Customer records, including when ensure-exists is called concurrently; concurrent callers converge on the same record (FR-010, FR-011).
  - Answer existence and lookup (FR-008, FR-009).
  - Participate in the atomic unit that Payment Operations opens for preparation (RD-04).
- Inputs: Billable reference.
- Outputs: Customer ID, existence flag.
- Owns: Customer records and Customer ID generation and uniqueness.
- Does not own: Payment records; any rule about payments.

### CMP-05 Payment Ledger

- Responsibilities:
  - Persist Payment records with the fields of PRODUCT_SPEC Outputs (FR-018).
  - Generate Payment IDs and guarantee uniqueness across all Payment records; a collision is resolved internally by generating another ID and is never visible to callers (FR-015).
  - Create a PENDING record from given values (FR-013).
  - Find records only within a Billable reference's scope (FR-020, FR-032).
  - List a Billable reference's records ordered by created_at descending (FR-031).
  - Provide exclusive access per Payment record and an atomic write of a Precomputed outcome.
  - Expose the record attributes (EL-009) and checkoutParameters (EL-010, FR-019).
  - Select stale PENDING records across all Billable references for the Expiry Command only, returning each record's Billable reference with its Payment ID (RD-26).
  - Define the prunable selection of EL-035: EXPIRED and updated_at older than EL-034 (FR-063, FR-064).
- Inputs: Billable reference; Payment ID; creation values; Precomputed outcome; requests for exclusive access; the staleness cut-off; the retention cut-off.
- Outputs: Payment records; lists; checkoutParameters; a persistence failure when a write cannot be completed.
- Owns: the stored Payment records and the guarantee that a write is all-or-nothing; Payment ID generation and uniqueness; the mechanism of exclusive access.
- Does not own: deciding when to take exclusive access (Payment Operations); deciding any field value (Payment Lifecycle); any PortOne contact.

### CMP-06 Payment Lifecycle

- Responsibilities:
  - Hold every state rule of a Payment record as pure decisions with no persistence and no network contact.
  - Completion decision: given a record, a Gateway result, and the current time, either produce the Precomputed outcome (PAID, paid_at = current time) or report ERR-006 or ERR-007 (FR-023).
  - Refund decision: given a record, a reason-validated request and an optional amount, either report ERR-008 or ERR-010, or produce the Precomputed outcome with absolute cancelled_amount and status (FR-028, FR-029).
  - Expiry decision: given a PENDING record, a Gateway result in its expiry form, and the current time, produce the Precomputed outcome PAID (paid_at = current time) when paid with the same total, the Precomputed outcome EXPIRED when the result means "not paid at the time of the check", or no change otherwise (FR-060 to FR-062). Which results mean that follows PRODUCT_SPEC External Systems "PortOne V2 lookup of abandoned payments".
  - Completion decision accepts an EXPIRED record as it accepts a PENDING one (FR-021).
  - Amount-mismatch decision: given a record and a Gateway result, tell whether PortOne reported paid with a total that differs from amount, and produce both Precomputed outcomes of SF-9 (FR-068 to FR-070).
  - Define the allowed transitions listed in Core Concepts.
- Inputs: Payment record state; Gateway result; refund amount; current time (supplied by the caller).
- Outputs: a Precomputed outcome or a named error condition.
- Owns: all transition rules, the amount comparison, and the refund arithmetic.
- Does not own: reading the clock, reading or writing records, contacting PortOne, ordering of checks across components (Payment Operations).

### CMP-07 Payment Operations

- Responsibilities:
  - Run preparation, completion, refund, and expiry sequences exactly as SF-1, SF-2, SF-3, SF-7.
  - Validate preparation arguments (ERR-004, ERR-005) and the refund reason (ERR-009).
  - Own the order in which checks happen (RD-09).
  - Open the atomic unit around Customer ensure and Payment creation (RD-04).
  - Hold exclusive access from the state read to the end of the write (RD-10).
  - Supply the current time to Payment Lifecycle.
  - Apply the persistence retry rule (RD-11).
  - Let PortOne exceptions and persistence failures pass without wrapping (ERR-011), except the failed cancellation of SF-9, which it records (FR-070) and attaches to ERR-007 as the previous exception.
  - Run SF-9 and read EL-037 for it.
- Inputs: Billable reference, Payment ID, amount, order name, reason, refund amount.
- Outputs: the Payment record after the operation; named exceptions of ERR-001, ERR-003 to ERR-011; persistence failure.
- Owns: operation sequencing, check order, exclusive access scope, persistence retry, the guarantee that a failed operation changes no record (except RD-11).
- Does not own: state rules (Lifecycle), storage (Ledger, Registry), PortOne contact (Gateway).

### CMP-08 PortOne Gateway

- Responsibilities:
  - Be the only component that sends requests to PortOne.
  - Lookup: request the payment by Payment ID; return the Gateway result (FR-021).
  - Cancel: send the Payment ID and reason, with the amount only when an amount is given (FR-025 to FR-027).
  - Choose the base address (FR-005, FR-006).
  - Check that the api secret is configured before every request; when absent, raise ConfigurationException and send nothing (ERR-001).
  - Let PortOne failures, including PortOne reporting that the payment is unknown, pass through unmodified (ERR-011, RD-12).
  - Expiry lookup: the same request, except that PortOne reporting the payment unknown yields a Gateway result saying so instead of an exception (RD-28). Every other failure passes through unmodified (ERR-016).
- Inputs: Payment ID, reason, optional amount; Configuration values EL-015, EL-017.
- Outputs: Gateway result; cancellation acceptance; ConfigurationException or the PortOne exception.
- Owns: the single door to PortOne and the use of the api secret.
- Does not own: interpreting the Gateway result (Lifecycle), any record, any retry of PortOne requests.
- Depends on lookup and cancellation assumptions at the external boundary. Lookup behavior was verified by T-003, payment completion through the Package by T-011, and cancellation by T-010; observations are recorded for each. The lookup of abandoned payments was verified by T-021, and the automatic cancellation of SF-9 by T-025.

### CMP-09 Webhook Verifier

- Responsibilities:
  - Decide authenticity of a webhook from the raw body, the three signature headers, and the secret (FR-035).
  - Reject a missing header, an invalid signature, or a timestamp outside the allowed range; the allowed range is fixed by the verifier and is not configurable (RD-06).
  - Return a Verified webhook for any webhook type, including unrecognized types (FR-037).
  - Report a body that cannot be turned into a Verified webhook as a verification failure (RD-13).
- Inputs: raw body (unmodified, undecoded), headers, secret.
- Outputs: Verified webhook, or failure.
- Owns: every authenticity decision.
- Does not own: reading Configuration, responding, dispatching, any record.

### CMP-10 Webhook Receiver

- Responsibilities:
  - Handle requests to the webhook route (EL-011).
  - Check that the webhook secret is configured; when absent respond HTTP 500, dispatch nothing (ERR-002).
  - Pass the raw body, headers, secret to the Webhook Verifier.
  - On failure respond HTTP 400, dispatch nothing (ERR-012).
  - On success dispatch exactly one WebhookReceived event with the Verified webhook and the webhook-id header value (FR-036, EL-021), then respond HTTP 200 with an empty body (FR-038).
  - Let an exception from a listener propagate; it is not caught (RD-14).
- Inputs: HTTP POST request; EL-016 from Configuration.
- Outputs: HTTP response (EL-014); one WebhookReceived event per verified request.
- Owns: the webhook response codes; event dispatch; the webhook secret's hand-off to the Verifier.
- Does not own: verification, Customer records, Payment records (FR-039), de-duplication of webhooks (RD-20).

### CMP-11 Checkout Controller (stub, EL-029)

- Responsibilities:
  - `show`: read EL-022, EL-023 from Configuration; when either is absent tell Checkout View to render the Error state with the button disabled (ERR-013); otherwise render Checkout View with the looked-up product's name and price and with the values and the product identifier available to Checkout Script (FR-043).
  - `prepare`: call EL-004 on the authenticated user with the price as the amount and the name as the order name of the product from the product lookup; never read an amount or order name from the request; respond with the EL-010 array (FR-045 to FR-047).
  - Product lookup: one lookup used by `show` and `prepare`; it may read a product identifier from the request; the published stub returns a sample product the Developer replaces with a database lookup (FR-045a).
  - `complete`: call EL-005 on the authenticated user with the Payment ID from the request; never read an amount; respond with the status of the returned record and, for PAID, the URL of `completed` for that Payment ID (FR-053, FR-054).
  - `completed`: call EL-008 on the authenticated user with the Payment ID from the path; render Completion View when the record is PAID, PARTIALLY_CANCELLED, or CANCELLED; otherwise respond HTTP 404 (FR-066).
  - Map PaymentNotFoundException, PaymentNotPaidException, AmountMismatchException to HTTP 422 with the key `error`; ConfigurationException and the PortOne exception to HTTP 500 with the key `error` (ERR-014, ERR-015). Every other exception propagates unmodified (RD-18).
- Inputs: requests via CMP-14 from an authenticated user.
- Outputs: rendered views; structured responses to Checkout Script.
- Owns: the product lookup; the mapping of errors to responses; which statuses may show the completion page.
- Does not own: authentication (Application), any record, any PortOne contact.

### CMP-12 Checkout View (stub, EL-025)

- Responsibilities:
  - Contain the start-payment button (EL-026) and the status slot (EL-027) (FR-044).
  - Render states: Initial (button enabled, idle), Loading (button disabled, in-progress), Success (button disabled, paid), Error (button enabled, error). Error caused by ERR-013 keeps the button disabled.
  - Show the looked-up product's name and price.
  - Hand the store ID, channel key, and product identifier to Checkout Script.
  - Load Checkout Script as part of the rendered page.
- Inputs: values and a missing-configuration flag from Checkout Controller; state changes from Checkout Script.
- Outputs: what the Payer sees.
- Owns: presentation of the four states; placeholder status text, which the Developer replaces.
- Does not own: deciding the state (Checkout Script, Checkout Controller).

### CMP-13 Checkout Script (stub, EL-028)

- Responsibilities:
  - On click: request `prepare` with the product identifier and set Loading (FR-048).
  - Call the PortOne browser SDK `requestPayment` with store ID, channel key, paymentId, orderName, totalAmount, customerId, a payment method constant, a currency constant (FR-049). The two constants are defined at the top of the file (FR-050).
  - On a result with an error code: set Error and do not request `complete` (FR-051).
  - On a result without an error code: request `complete` with the Payment ID (FR-052).
  - Set Success only when `complete` responds with status PAID, then navigate to the URL in that response. Every other outcome sets Error and stays (FR-055, FR-056, RD-17).
- Inputs: Payer click; responses of `prepare` and `complete`; the SDK result.
- Outputs: requests to CMP-14 routes; state changes to Checkout View.
- Owns: the browser-side sequence and the currency and payment method constants. The core has no currency concept.
- Does not own: any amount or order name; any record.
- Depends on the unverified assumptions about the PortOne browser SDK. They are isolated here.

### CMP-14 Checkout Routes (stub, EL-030)

- Responsibilities:
  - Define `portone.checkout` (GET), `portone.checkout.prepare` (POST), `portone.checkout.complete` (POST), `portone.checkout.completed` (GET, Payment ID in the path), each requiring an authenticated user (FR-042). An unauthenticated request never reaches CMP-11 and creates no Payment record.
- Inputs: the Application's decision to include the file.
- Outputs: routes bound to CMP-11 actions.
- Owns: the authentication requirement on the four routes.
- Does not own: authentication itself (Application); registration (the Developer includes the file, CMP-02 never does).

### CMP-17 Completion View (stub, EL-036)

- Responsibilities:
  - Show the order name, amount, Payment ID, paid_at, and current status of one paid Payment record (FR-067).
- Inputs: the Payment record from Checkout Controller `completed`.
- Outputs: what the Payer sees after paying.
- Owns: presentation of the completion page; placeholder text, which the Developer replaces.
- Does not own: deciding which record or status may be shown (CMP-11).

### CMP-15 Expiry Command (EL-031)

- Responsibilities:
  - Read EL-033 from Configuration and the current time; compute the staleness cut-off.
  - Ask the Payment Ledger for the stale PENDING records and queue exactly one Expiry Job per record (FR-057).
- Inputs: EL-033; the current time.
- Outputs: queued Expiry Jobs.
- Owns: the choice of which records to check in one run.
- Does not own: any decision about a record, any write, any PortOne contact, scheduling (Application).

### CMP-16 Expiry Job (EL-032)

- Responsibilities:
  - Hand its Billable reference and Payment ID to Payment Operations to run SF-7.
  - Let ConfigurationException and PortOne failures propagate so the Application's queue records the failure (ERR-016, RD-27).
- Inputs: a Billable reference and a Payment ID.
- Outputs: none; the record change is made by Payment Operations.
- Owns: being the queued unit of one expiry check.
- Does not own: the state read, the decision, the write (CMP-07, CMP-06, CMP-05), running the queue (Application).

## Component Responsibilities

Each responsibility has exactly one owner.

| Responsibility                                                              | Sole owner                   | Spec                                                                 |
| --------------------------------------------------------------------------- | ---------------------------- | -------------------------------------------------------------------- |
| Reading and defaulting configuration values                                 | CMP-01                       | FR-004 to FR-006                                                     |
| Publishing files; registering the webhook route                             | CMP-02                       | FR-001 to FR-003, FR-033, FR-034, FR-040, FR-041                     |
| Binding a call to a Billable model                                          | CMP-03                       | FR-007                                                               |
| Customer record existence, uniqueness, Customer ID                          | CMP-04                       | FR-008 to FR-012                                                     |
| Payment record storage, Payment ID uniqueness, scoped lookup, history order | CMP-05                       | FR-013, FR-015, FR-018, FR-020, FR-031, FR-032                       |
| checkoutParameters                                                          | CMP-05                       | FR-019                                                               |
| Status transitions, completion decision, refund arithmetic                  | CMP-06                       | FR-023, FR-028, FR-029, ERR-006, ERR-007, ERR-008, ERR-010           |
| Operation sequencing, check order, exclusive access, persistence retry      | CMP-07                       | FR-021, FR-022, FR-024, FR-030, ERR-003 to ERR-005, ERR-009, ERR-011 |
| Contact with PortOne; api secret use; ERR-001                               | CMP-08                       | FR-021, FR-025 to FR-027, ERR-001                                    |
| Webhook authenticity                                                        | CMP-09                       | FR-035, FR-037, ERR-012                                              |
| Webhook response and event dispatch                                         | CMP-10                       | FR-036, FR-038, ERR-002                                              |
| Webhook non-interference with records                                       | CMP-10 (by having no access) | FR-039                                                               |
| Product lookup for the stub flow                                            | CMP-11                       | FR-043, FR-045, FR-045a, FR-046                                      |
| Error-to-response mapping in the stub flow                                  | CMP-11                       | ERR-014, ERR-015                                                     |
| Missing store ID or channel key detection                                   | CMP-11                       | ERR-013                                                              |
| Rendering of the four checkout states                                       | CMP-12                       | SCR-006                                                              |
| Browser payment sequence                                                    | CMP-13                       | FR-048 to FR-052, FR-055, FR-056                                     |
| Completion page selection and rendering                                     | CMP-11, CMP-17               | FR-066, FR-067                                                       |
| Authentication requirement on stub routes                                   | CMP-14                       | FR-042                                                               |
| Selecting stale PENDING records and queuing one check each                  | CMP-15                       | FR-057, FR-065                                                       |
| Queued unit of one expiry check; failure propagation                        | CMP-16                       | ERR-016                                                              |
| Expiry decision                                                             | CMP-06                       | FR-060, FR-061, FR-062                                               |
| Expiry sequencing and the no-op for non-PENDING records                     | CMP-07                       | FR-058, FR-059                                                       |
| Prunable selection of expired records                                       | CMP-05                       | FR-063, FR-064                                                       |
| Amount-mismatch decision and its two outcomes                               | CMP-06                       | FR-069, FR-070                                                       |
| Amount-mismatch sequence, automatic cancellation, repeat completion         | CMP-07                       | FR-068, FR-071, FR-072, ERR-007                                      |

## Responsibility Boundaries

### Allowed dependencies (caller -> callee)

- CMP-03 -> CMP-04, CMP-05, CMP-07
- CMP-07 -> CMP-04, CMP-05, CMP-06, CMP-08
- CMP-08 -> CMP-01
- CMP-10 -> CMP-01, CMP-09
- CMP-11 -> CMP-01, CMP-03, CMP-12, CMP-17
- CMP-13 -> CMP-14 routes, the PortOne browser SDK
- CMP-02 -> CMP-10 (route target), CMP-01 (EL-018), published file groups
- CMP-15 -> CMP-01, CMP-05, CMP-16 (dispatch only)
- CMP-16 -> CMP-07
- CMP-04, CMP-05, CMP-06, CMP-09 depend on no other component.

### Forbidden dependencies

- CMP-09 and CMP-10 never depend on CMP-04, CMP-05, CMP-07 (webhooks never touch records).
- CMP-05 and CMP-04 never depend on CMP-08 (storage never contacts PortOne).
- CMP-06 never depends on anything (pure decisions).
- CMP-03 never depends on CMP-06 or CMP-08.
- CMP-15 never depends on CMP-06, CMP-07, or CMP-08; it decides nothing about a record and never contacts PortOne.
- CMP-16 never depends on CMP-05, CMP-06, or CMP-08 directly; its only door is CMP-07.
- CMP-11 to CMP-14 and CMP-17 never depend on CMP-04, CMP-05, CMP-06, CMP-07, CMP-08 directly; the only door into the Core is CMP-03.
- The Core never depends on CMP-11 to CMP-14 or CMP-17.
- No component other than CMP-08 sends a request to PortOne; no component other than CMP-09 decides authenticity.

### Secret ownership

- The api secret is read only by CMP-08.
- The webhook secret is read by CMP-10 and passed to CMP-09 as an input.
- No other component receives either secret.

### Ownership transfer

- After a Developer publishes the stub files, CMP-11 to CMP-14 and CMP-17 belong to the Application. The Package keeps no live link to the copies and does not update them.

## Data Flow

### DF-1 Preparation

| Step | From -> To                           | Data                                                |
| ---- | ------------------------------------ | --------------------------------------------------- |
| 1    | Caller -> CMP-03                     | amount, order name, calling model                   |
| 2    | CMP-03 -> CMP-07                     | Billable reference, amount, order name              |
| 3    | CMP-07 -> CMP-04                     | Billable reference (ensure Customer record)         |
| 4    | CMP-04 -> CMP-07                     | Customer ID                                         |
| 5    | CMP-07 -> CMP-05                     | Billable reference, Customer ID, amount, order name |
| 6    | CMP-05 -> CMP-07 -> CMP-03 -> Caller | new PENDING Payment record                          |

Steps 3 to 5 are one atomic unit. Written data: possibly one Customer record, one Payment record. PortOne is not contacted.

### DF-2 Completion

| Step | From -> To                  | Data                                         |
| ---- | --------------------------- | -------------------------------------------- |
| 1    | CMP-03 -> CMP-07            | Billable reference, Payment ID               |
| 2    | CMP-07 -> CMP-05            | scoped lookup, exclusive access request      |
| 3    | CMP-05 -> CMP-07            | Payment record (current state)               |
| 4    | CMP-07 -> CMP-08            | Payment ID                                   |
| 5    | CMP-08 -> PortOne -> CMP-08 | lookup request; Gateway result               |
| 6    | CMP-08 -> CMP-07            | Gateway result                               |
| 7    | CMP-07 -> CMP-06            | Payment record, Gateway result, current time |
| 8    | CMP-06 -> CMP-07            | Precomputed outcome or named error           |
| 9    | CMP-07 -> CMP-05            | Precomputed outcome (absolute values)        |

Steps 4 to 9 happen only when the record is PENDING or EXPIRED. Written data: status, paid_at of one Payment record.

### DF-3 Refund

| Step | From -> To                  | Data                                                    |
| ---- | --------------------------- | ------------------------------------------------------- |
| 1    | CMP-03 -> CMP-07            | Billable reference, Payment ID, reason, optional amount |
| 2    | CMP-07 -> CMP-05            | scoped lookup, exclusive access request                 |
| 3    | CMP-07 -> CMP-06            | Payment record, optional amount                         |
| 4    | CMP-06 -> CMP-07            | Precomputed outcome or named error                      |
| 5    | CMP-07 -> CMP-08            | Payment ID, reason, optional amount                     |
| 6    | CMP-08 -> PortOne -> CMP-08 | cancellation request; acceptance                        |
| 7    | CMP-07 -> CMP-05            | Precomputed outcome (absolute values)                   |

Written data: status, cancelled_amount of one Payment record. Step 5 occurs only after step 4 produced an outcome.

### DF-4 Webhook

| Step | From -> To          | Data                                                       |
| ---- | ------------------- | ---------------------------------------------------------- |
| 1    | PortOne -> CMP-10   | raw body, webhook-id, webhook-signature, webhook-timestamp |
| 2    | CMP-01 -> CMP-10    | webhook secret                                             |
| 3    | CMP-10 -> CMP-09    | raw body, headers, secret                                  |
| 4    | CMP-09 -> CMP-10    | Verified webhook, or failure                               |
| 5    | CMP-10 -> listeners | WebhookReceived(webhook, webhookId)                        |
| 6    | CMP-10 -> PortOne   | HTTP 200 empty body, or HTTP 400, or HTTP 500              |

No record is read or written.

### DF-5 Checkout stubs

| Step | From -> To                    | Data                                                                                                             |
| ---- | ----------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| 1    | Payer -> CMP-12/CMP-13        | click                                                                                                            |
| 2    | CMP-13 -> CMP-11 (`prepare`)  | product identifier only; no amount, no order name                                                                |
| 3    | CMP-11 -> CMP-03              | price and name of the looked-up product                                                                          |
| 4    | CMP-11 -> CMP-13              | EL-010 array                                                                                                     |
| 5    | CMP-13 -> SDK                 | store ID, channel key, paymentId, orderName, totalAmount, customerId, payment method constant, currency constant |
| 6    | SDK -> CMP-13                 | result with or without error code                                                                                |
| 7    | CMP-13 -> CMP-11 (`complete`) | Payment ID only                                                                                                  |
| 8    | CMP-11 -> CMP-13              | status and, for PAID, the `completed` URL; or error with key `error`                                             |
| 9    | CMP-13 -> CMP-11 (`completed`) | Payment ID in the path (browser navigation)                                                                      |
| 10   | CMP-11 -> CMP-03 -> CMP-17    | the scoped Payment record (EL-008)                                                                               |

### DF-6 Queries

- EL-001, EL-002, EL-003: CMP-03 <-> CMP-04.
- EL-007, EL-008: CMP-03 <-> CMP-05.

### DF-7 Expiry

| Step | From -> To                  | Data                                                        |
| ---- | --------------------------- | ----------------------------------------------------------- |
| 1    | CMP-15 -> CMP-01            | EL-033                                                      |
| 2    | CMP-15 -> CMP-05            | staleness cut-off                                           |
| 3    | CMP-05 -> CMP-15            | Billable reference and Payment ID of each stale record      |
| 4    | CMP-15 -> queue             | one Expiry Job per record                                   |
| 5    | queue -> CMP-16 -> CMP-07   | Billable reference, Payment ID                              |
| 6    | CMP-07 -> CMP-05            | scoped lookup, exclusive access request                     |
| 7    | CMP-07 -> CMP-08 -> PortOne | Payment ID (expiry lookup)                                  |
| 8    | CMP-07 -> CMP-06            | Payment record, Gateway result, current time                |
| 9    | CMP-07 -> CMP-05            | Precomputed outcome (PAID or EXPIRED), or nothing           |

Steps 7 to 9 happen only when the record is still PENDING. Written data: status, and paid_at for PAID, of one Payment record.

### DF-8 Prune

- model:prune -> CMP-05 prunable selection (EXPIRED, updated_at older than EL-034) -> deletion of the selected records.

## Architectural Rules

- AR-01: Only CMP-07 changes a Payment record after creation, and only by writing a Precomputed outcome produced by CMP-06. The only deletion is model:prune through the CMP-05 prunable selection of EL-035.
- AR-02: CMP-06 never reads the clock; CMP-07 supplies the time.
- AR-03: Payment Operations runs checks in the order of RD-09 and stops at the first failure.
- AR-04: A PortOne request that changes remote state (cancellation) is sent only while the caller holds exclusive access on the record, only after the Precomputed outcome exists, and is never repeated by any retry.
- AR-05: Records are written as absolute values computed before the PortOne request, so a repeated write of the same outcome is harmless.
- AR-06: All records reachable from a Billable model are reached through its Billable reference; lookup by Payment ID alone, without scope, does not exist anywhere in the Core. The two unscoped selections are the stale PENDING selection for CMP-15, which hands each record's own Billable reference onward (RD-26), and the prunable selection of EL-035.
- AR-07: Exceptions from CMP-08 and CMP-05 pass through CMP-07 and CMP-03 unmodified.
- AR-08: The webhook route is the only Package route that is open to unauthenticated requests without a forgery token. No stub route is.
- AR-09: Authenticity is decided on the raw body exactly as received, before any decoding.
- AR-10: Each Core component stores no state outside Customer records and Payment records; there is no cache of PortOne data.
- AR-11: Behavior that depends on an unverified PortOne assumption lives only in CMP-08 and CMP-13.
- AR-12: Amounts in the Core are plain integers with no currency attached.
- AR-13: A component refuses work it does not own by having no access to it (see Forbidden dependencies), not by checking at run time.
- AR-14: The Package registers no schedule; the Application decides whether and when EL-031 and model:prune run (FR-065).

## Failure Boundaries

| Failure                                                              | Detected by       | Contained at                  | Effect on records                                                                     | Seen by                                                            |
| -------------------------------------------------------------------- | ----------------- | ----------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| api secret absent (ERR-001)                                          | CMP-08            | CMP-08                        | none                                                                                  | Developer: ConfigurationException; stub flow: HTTP 500             |
| webhook secret absent (ERR-002)                                      | CMP-10            | CMP-10                        | none                                                                                  | PortOne: HTTP 500                                                  |
| unknown Payment ID or other model's ID (ERR-003)                     | CMP-05 via CMP-07 | CMP-07                        | none                                                                                  | PaymentNotFoundException                                           |
| invalid arguments (ERR-004, ERR-005, ERR-009)                        | CMP-07            | CMP-07                        | none; no Customer record either                                                       | InvalidArgumentException                                           |
| status not paid at PortOne (ERR-006)                                 | CMP-06            | CMP-07                        | unchanged                                                                             | PaymentNotPaidException                                            |
| paid amount differs (ERR-007)                                        | CMP-06            | CMP-07                        | AMOUNT_MISMATCH_CANCELLED after PortOne cancelled it (SF-9)                           | AmountMismatchException; expiry job ends normally                  |
| automatic cancellation fails (FR-070)                                | CMP-08            | CMP-07                        | AMOUNT_MISMATCH; the Developer handles it                                             | AmountMismatchException (wasCancelled false); expiry job fails     |
| local write fails after PortOne accepted the automatic cancellation  | CMP-05            | CMP-07 retry, then propagates | stays PENDING or EXPIRED while PortOne shows cancelled: **known divergence**          | persistence failure                                                |
| status not refundable (ERR-008)                                      | CMP-06            | CMP-07                        | unchanged                                                                             | InvalidPaymentStatusException                                      |
| refund amount invalid (ERR-010)                                      | CMP-06            | CMP-07                        | unchanged                                                                             | InvalidRefundAmountException                                       |
| PortOne request fails or PortOne does not know the payment (ERR-011) | CMP-08            | not contained; passes through | unchanged                                                                             | the PortOne exception                                              |
| local write fails after PortOne accepted a lookup (completion)       | CMP-05            | CMP-07 retry, then propagates | stays PENDING; the Developer can call completion again                                | persistence failure                                                |
| local write fails after PortOne accepted a cancellation (refund)     | CMP-05            | CMP-07 retry, then propagates | stays PAID or PARTIALLY_CANCELLED while PortOne shows cancelled: **known divergence** | persistence failure                                                |
| webhook verification fails or body undecodable (ERR-012)             | CMP-09            | CMP-10                        | none                                                                                  | PortOne: HTTP 400                                                  |
| unknown webhook type                                                 | not a failure     | n/a                           | none                                                                                  | listeners receive it                                               |
| listener throws                                                      | host              | not contained                 | none                                                                                  | the host's error handling; PortOne sees an error and may redeliver |
| store ID or channel key absent in stub `show` (ERR-013)              | CMP-11            | CMP-12                        | none                                                                                  | Payer: Error state, button disabled                                |
| listed exceptions in `complete` (ERR-014, ERR-015)                   | CMP-11            | CMP-11                        | per cause                                                                             | HTTP 422 or 500 with key `error`                                   |
| any other exception in `complete` or `prepare`                       | host              | not contained                 | per cause                                                                             | host error handling; CMP-13 shows Error                            |
| SDK reports an error code                                            | CMP-13            | CMP-13                        | Payment record stays PENDING                                                          | Payer: Error state                                                 |
| SDK throws, `prepare` fails, `complete` returns non-error non-PAID   | CMP-13            | CMP-13                        | per cause                                                                             | Payer: Error state                                                 |
| expiry job finds the record gone or not PENDING (FR-058)             | CMP-07            | CMP-07                        | none                                                                                  | nobody; the job ends                                               |
| expiry lookup gives no decision (FR-062)                             | CMP-06            | CMP-07                        | stays PENDING; the next run of EL-031 checks it again                                 | nobody; the job ends                                               |
| api secret absent or PortOne fails during expiry (ERR-016)           | CMP-08            | not contained; job fails      | stays PENDING                                                                         | the Application's queue (failed job)                               |
| local write fails during expiry                                      | CMP-05            | CMP-07 retry, then propagates | stays PENDING; the next run checks it again                                           | the Application's queue (failed job)                               |
| expired record paid later at PortOne                                 | not a failure     | n/a                           | stays EXPIRED until EL-005 is called; then PAID (FR-021)                              | Developer or an Application webhook listener                       |
| concurrent calls on one Payment record                               | CMP-05, CMP-07    | exclusive access              | serialized                                                                            | callers wait                                                       |
| concurrent Customer creation                                         | CMP-04            | CMP-04                        | one record                                                                            | callers converge                                                   |

Known divergence (refund, post-acceptance write failure), full rule in RD-11:

- The Package retries only the local write, at most 3 attempts in total, writing identical absolute values and never re-sending the cancellation.
- If all attempts fail the persistence failure propagates unmodified. That exception is the only signal the Package gives.
- Calling the refund again does not repair the record: the record is still refundable locally, so a second cancellation request would be sent and PortOne is expected to reject it. Repair is a manual action by the Developer.

## Non-Goals

- No component other than CMP-07 changes Payment record state.
- No path exists from the Webhook Receiver to Customer records or Payment records; webhooks cannot change payment state.
- No reconciliation, polling, or background synchronization with PortOne other than the Application-scheduled expiry check of stale PENDING records (SF-7).
- No automatic retry of any PortOne request.
- No notification channel for a failed local write other than the propagated exception (RD-11).
- No de-duplication of webhooks; a repeated delivery produces a repeated event.
- No entity for a payment attempt; each preparation creates a new Payment record. Abandoned PENDING records are expired only by SF-7 and deleted only by SF-8, both run by the Application.
- No deletion of PAID, PARTIALLY_CANCELLED, or CANCELLED records.
- No behavior is defined for what happens to Customer records and Payment records when a Billable model is deleted.
- No currency in the Core.
- No cache of PortOne responses.
- No authorization beyond Scope; the Package decides nothing about whether a Billable model may pay.
- No user interface beyond the replaceable stubs.
- No Package-side update of stub files already published.

## Architectural Invariants

- INV-01: A Payment record has exactly one owner, its Billable reference, which never changes.
- INV-02: A Billable reference has at most one Customer record. Customer IDs are unique across all Customer records.
- INV-03: Payment IDs are unique across all Payment records and are never reused.
- INV-04: A Payment record's customer_id equals the Customer ID of its owner at the time of creation.
- INV-05: Status transitions follow only the transitions listed in Core Concepts. CANCELLED, AMOUNT_MISMATCH_CANCELLED, and AMOUNT_MISMATCH never change. EXPIRED changes only to PAID, AMOUNT_MISMATCH_CANCELLED, or AMOUNT_MISMATCH.
- INV-06: `0 <= cancelled_amount <= amount` always, except AMOUNT_MISMATCH_CANCELLED, where cancelled_amount = paid_amount; cancelled_amount never decreases. amount never changes after preparation.
- INV-07: PENDING and EXPIRED imply cancelled_amount = 0 and paid_at = null. PAID implies cancelled_amount = 0 and paid_at non-null. PARTIALLY_CANCELLED implies `0 < cancelled_amount < amount` and paid_at non-null. CANCELLED implies cancelled_amount = amount and paid_at non-null. AMOUNT_MISMATCH_CANCELLED implies paid_amount non-null and different from amount, cancelled_amount = paid_amount, and paid_at = null. AMOUNT_MISMATCH implies paid_amount non-null and different from amount, cancelled_amount = 0, and paid_at = null. Every other status implies paid_amount = null.
- INV-08: A record becomes PAID only in a completion or an expiry check in which PortOne reported paid and the total paid amount equalled the record's amount.
- INV-09: No PortOne request is sent when ERR-003, ERR-004, ERR-005, ERR-008, ERR-009, ERR-010 apply, when a record is neither PENDING nor EXPIRED at completion (FR-022, FR-071), when a record is not PENDING when an expiry job runs, or during preparation, any webhook handling, CMP-15, or pruning.
- INV-10: A completion or refund that fails before the local write leaves the record unchanged. The one exception is the post-acceptance write failure of a refund (RD-11).
- INV-11: Between reading a record for a decision and writing the resulting outcome, no other operation of the Package reads that record for a decision or writes it.
- INV-12: Every PortOne request originates in CMP-08. Every authenticity decision originates in CMP-09.
- INV-13: No event is dispatched without a successful verification, and exactly one event is dispatched per verified request.
- INV-14: A webhook request reads and writes no Customer record and no Payment record.
- INV-15: Raw body bytes reach CMP-09 unmodified.
- INV-16: The api secret exists only inside CMP-08's use; the webhook secret only in CMP-10 and as an input to CMP-09.
- INV-17: In the stub flow, amount and order name come only from the CMP-11 product lookup; the request supplies at most a product identifier; `complete` reads only the Payment ID from the request.
- INV-18: CMP-12 is in the Success state, and the browser navigates to the completion page, only after `complete` responded with status PAID.
- INV-19: The stub routes are never registered by the Package; each requires an authenticated user.
- INV-20: The Core runs identically with the stubs absent.
- INV-23: The completion page shows only a Payment record of the authenticated user that has been paid (PAID, PARTIALLY_CANCELLED, CANCELLED).
- INV-21: A Payment record is deleted only when its status is EXPIRED and its updated_at is older than EL-034.
- INV-22: A record becomes EXPIRED only after a lookup in which PortOne reported a "not paid at the time of the check" result. EXPIRED is not a promise that the payment can never be paid; EL-005 completes it if it is paid later.

## Resolved Decisions

Each entry: what was ambiguous, what was decided, why. Entries marked **owner-confirmed** were answered by the document owner.

- RD-01: Ambiguous: PRODUCT_SPEC defines transitions but names no owner. Decided: CMP-06 computes, CMP-07 applies, CMP-05 stores; nobody else writes state (AR-01). Why: one owner per rule.
- RD-02: Ambiguous: "reference to the owning Billable model" does not say whether kind is included. Decided: kind and key. Why: models of different kinds may share keys, and Scope must not leak across them.
- RD-03: Ambiguous: uniqueness of Customer records under concurrent EL-003. Decided: CMP-04 guarantees one record per Billable reference and a unique Customer ID, concurrent callers converge. Why: FR-011 and AC-006 require exactly one record; the guarantee must hold under concurrency, not only sequentially.
- RD-04: Ambiguous: ERR-004/ERR-005 say "create no record", while FR-014 creates a Customer record during preparation. Decided: validation happens before anything is written, and Customer ensure plus Payment creation form one atomic unit owned by CMP-07. Why: a failed preparation must leave no Customer record, and a Customer record without its Payment must not appear from a partial failure.
- RD-05: Ambiguous: whether webhooks may ever read records. Decided: neither read nor write; CMP-09 and CMP-10 have no dependency on records (FR-039). Why: prevents accidental state changes from a webhook path.
- RD-06: Ambiguous: who owns the "allowed range" of the webhook timestamp. Decided: CMP-09 owns it, fixed, not configurable. Why: PRODUCT_SPEC defines no configuration value for it.
- RD-07: Ambiguous: who detects missing store ID or channel key (ERR-013). Decided: CMP-11 `show` detects, CMP-12 renders. Why: CMP-11 is the only stub component that reads Configuration.
- RD-08: Ambiguous: order of records with equal created_at. Decided: unspecified; callers must not rely on it. Why: PRODUCT_SPEC requires only descending created_at.
- RD-09 (owner-confirmed): Ambiguous: order of checks when several errors apply. Decided: local checks first, configuration check immediately before the PortOne request.
  - Completion: ERR-003, then the return of FR-022 for a record that is neither PENDING nor EXPIRED, then ERR-001 (inside CMP-08), then the PortOne lookup, then ERR-006, ERR-007.
  - Refund: ERR-003, ERR-009, ERR-008, ERR-010, then ERR-001 (inside CMP-08), then the cancellation.
  - Preparation: ERR-004, ERR-005.
- RD-10 (owner-confirmed): Ambiguous: concurrent completion or refund on one record. Decided: exclusive access per Payment record from the state read through the end of the write, so FR-022, ERR-008, ERR-010 are judged on the latest state. Why: prevents double completion and over-refund. Cost: a concurrent caller waits for the PortOne round trip.
- RD-11 (owner requirement, decided by Claude at the owner's request): Ambiguous: a local write failure after PortOne accepted the request; ERR-011 covers only PortOne failures. Owner's requirement: the write must not fail; if it does, retry or notify so someone can act. Decided:
  - Prevention: the Precomputed outcome is computed before the PortOne request, so after PortOne accepts, the only remaining work is one atomic write of absolute values.
  - Retry: CMP-07 attempts that write at most 3 times in total, immediately, with the same values, while still holding exclusive access. The PortOne request is never repeated. The retry count is fixed and not configurable. The same rule applies to completion for uniformity.
  - Notification: PRODUCT_SPEC defines no event or exception for this case and Outputs lists only WebhookReceived, so the Package adds none. The propagated persistence failure, unmodified, is the notification. The Application's error handling and the Developer's code are the channel.
  - Recovery: manual only (see Failure Boundaries). A refund repeated by the Developer is not a repair.
  - Open point for the owner: if automatic notification is wanted, PRODUCT_SPEC must first add an event or exception for it; this architecture cannot add a Package output on its own.
- RD-12 (owner-confirmed): Ambiguous: PortOne does not know the payment at completion. Decided: it is a PortOne failure (ERR-011) and passes through unmodified. ERR-006 is raised only when PortOne knows the payment and its status is not paid.
- RD-13 (owner-confirmed): Ambiguous: valid signature but undecodable body. Decided: treated as a verification failure: HTTP 400, no event.
- RD-14 (owner-confirmed): Ambiguous: a listener throws. Decided: the exception propagates; CMP-10 does not catch it and does not respond 200. Why: PortOne can redeliver.
- RD-15: Ambiguous: who reacts to an absent configuration value. Decided: each consumer at its point of use: CMP-08 for ERR-001, CMP-10 for ERR-002, CMP-11 for ERR-013. CMP-01 only reports absence.
- RD-16: Ambiguous: whether CMP-07 reads the clock. Decided: CMP-07 supplies the time to CMP-06. Why: CMP-06 stays pure.
- RD-17 (owner-confirmed): Ambiguous: PRODUCT_SPEC names only two ways CMP-13 ends (no error code -> complete -> PAID; complete error). Silent: `prepare` fails, the SDK throws instead of returning, `complete` responds without an error but with a status other than PAID (for example, the record is already CANCELLED). Decided: Success only when `complete` responds with status PAID; every other outcome sets Error. Why: a terminal state is required and Success must imply PAID.
- RD-18: Ambiguous: ERR-014 and ERR-015 list only some exceptions for `complete`. Decided: CMP-11 maps exactly those; every other exception (for example a persistence failure) propagates unmodified to the host's error handling, and CMP-13 shows Error by RD-17. **Gap-filling; to confirm.**
- RD-19 (owner-confirmed, revised for T-022): Ambiguous: repeated preparation for the same order and abandoned PENDING records. Decided: each preparation creates a new Payment record and nothing de-duplicates them. Abandoned records are expired only after a PortOne check (SF-7) and deleted only after the EXPIRED retention period (SF-8), both scheduled by the Application. Why: a PENDING record may be paid at PortOne even though completion never ran; deleting it without a check loses a paid payment.
- RD-20: Ambiguous: duplicate webhook deliveries. Decided: one event per verified request, no de-duplication.
- RD-21: Ambiguous: ownership of published stub files. Decided: transfer to the Application on publishing; the Package keeps no link.
- RD-22: Ambiguous: who may hold secrets. Decided: the api secret only for CMP-08; the webhook secret for CMP-10 and, as an input, CMP-09.
- RD-23: Ambiguous: where currency lives. Decided: only in CMP-13 as a constant; the Core handles plain integer amounts.
- RD-24: PortOne lookup and browser SDK assumptions are isolated to CMP-08 and CMP-13. Lookup behavior was confirmed by T-003 and, for abandoned payments, by T-021; completion through the Package by T-011, and cancellation by T-010; the observations are recorded.
- RD-25 (owner-confirmed): Ambiguous: how abandoned records are found. Decided: a periodic scan by the Expiry Command, not one delayed job per preparation. Why: a lost or never-dispatched delayed job would leave a record PENDING forever; the next scan always finds it again.
- RD-26: Ambiguous: AR-06 forbids unscoped lookup, but the scan must see every Billable reference. Decided: CMP-05 offers one unscoped selection, used only by CMP-15, returning each record's own Billable reference; everything after it runs scoped through CMP-07. Why: keeps every decision and write inside Scope.
- RD-27: Ambiguous: what an expiry job does when it cannot decide. Decided: a lookup result that is neither paid nor "not paid at the time of the check" ends the job with no change and no exception; ConfigurationException, PortOne failures, and persistence failures propagate unwrapped so the Application's queue records a failed job. Why: an undecidable status is normal and is retried by the next scan; an infrastructure failure must be visible.
- RD-28: Ambiguous: RD-12 makes "PortOne does not know the payment" a passed-through failure, but for an abandoned payment it is the expected answer. Decided: CMP-08 offers an expiry form of the lookup that returns it as a Gateway result; completion keeps RD-12. Why: knowledge of PortOne error shapes stays in CMP-08 (AR-11).
- RD-29 (owner-confirmed): Defaults: EL-033 = 1440 minutes, EL-034 = 90 days.
- RD-30 (owner-confirmed): Ambiguous: T-021 showed that no lookup result is final (a FAILED payment ID can still be paid). Decided: after EL-033, PAYMENT_NOT_FOUND, READY, and FAILED all expire the record; recovery of a later payment is EL-005 on the EXPIRED record. Why: a stale READY would otherwise stay PENDING and be looked up on every run, and recovery is always available.
- RD-31 (owner-confirmed, T-025): Ambiguous: ERR-007 must both change the record and throw, but a throw inside exclusive access would undo the write. Decided: CMP-07 writes the SF-9 outcome, ends exclusive access, then raises ERR-007. Why: the record must keep the result so a repeated completion (browser and webhook at once) returns the same exception without another PortOne request (FR-071).
- RD-32 (owner-confirmed, T-025): Ambiguous: what the expiry job does after SF-9. Decided: an accepted cancellation ends the job normally (the record shows the result); a failed cancellation fails the job with the PortOne exception so the Application's queue records it, the same way as RD-27. Why: the payment was handled; only the failure needs attention.
- RD-33: Ambiguous: PortOne reports paid without a total paid amount. Decided: SF-9 does not run; completion raises ERR-007 with no paidAmount and leaves the record unchanged, and the expiry job makes no change (FR-062). Why: PRODUCT_SPEC relies on PortOne returning the total when paid, and the Package never cancels a payment whose paid amount it cannot record.
