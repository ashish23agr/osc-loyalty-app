/**
 * Enrolling a member at the till (M1, MD1, D10).
 *
 * The duplicate check is the server's — `uq_account_email` enforces D10 at the
 * database, and the endpoint answers `duplicate_email` with the existing
 * account id. This side's job is to ask for the least it can, validate what a
 * person can fix before spending a round trip, and turn the server's refusal
 * into something an assistant can act on.
 *
 * **MD1: an email address is optional.** Legacy members migrate without one and
 * enrol at the till without one. A form that demanded an email would refuse
 * exactly the customers this programme was built to keep.
 */

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * What is wrong with this form, before it is sent.
 *
 * Only things the assistant can fix by typing. Everything else — a duplicate, a
 * clash with a migrated record — is the server's to decide, because only the
 * server can see the other members.
 */
export function validate({firstName = '', lastName = '', email = '', postcode = ''} = {}) {
  const errors = {};

  if (lastName.trim() === '') {
    // A surname, because the no-email identity path is postcode plus surname
    // (MD1) and a member enrolled without one could not be found again.
    errors.lastName = 'A surname is needed to find this member again.';
  }

  if (email.trim() !== '' && !EMAIL.test(email.trim())) {
    errors.email = 'That email address does not look right.';
  }

  if (email.trim() === '' && postcode.trim() === '') {
    // Not a hard rule, a practical one: with neither, the only way back to this
    // member is the card, and cards get lost.
    errors.postcode = 'Add an email address or a postcode, so this member can be found again.';
  }

  return {valid: Object.keys(errors).length === 0, errors};
}

/** The payload the enrol endpoint expects, with blanks omitted rather than sent empty. */
export function toPayload({firstName = '', lastName = '', email = '', postcode = '', dobMonth = null, dobDay = null} = {}) {
    const payload = {last_name: lastName.trim()};

    if (firstName.trim() !== '') {
      payload.first_name = firstName.trim();
    }

    if (email.trim() !== '') {
      payload.email = email.trim();
    }

    if (postcode.trim() !== '') {
      payload.postcode = postcode.trim();
    }

    // MD2: a day and month with no year is a complete birthday for this
    // programme, and the enrolment form must accept one.
    if (dobMonth && dobDay) {
      payload.dob_month = Number(dobMonth);
      payload.dob_day = Number(dobDay);
    }

    return payload;
}

/**
 * The server refused. What does the assistant do now?
 *
 * A duplicate is the interesting one: it is not a failure, it is the programme
 * telling the till that this person is already a member (D10). The right
 * response is to open that member, not to argue with the form.
 */
export function enrolmentProblem(error) {
  if (error?.error === 'duplicate_email') {
    return {
      kind: 'duplicate',
      title: 'Already a member',
      detail: 'Someone is already enrolled with that email address. Open their account instead.',
      existingAccountId: error.payload?.error?.details?.existing_account_id ?? null,
    };
  }

  if (error?.error === 'validation_failed') {
    return {
      kind: 'validation',
      title: 'Check the details',
      detail: error.message ?? 'Some of what was entered could not be accepted.',
      fields: error.payload?.error?.details ?? {},
    };
  }

  if (error?.error === 'unreachable' || error?.error === 'no_session') {
    return {
      kind: 'offline',
      title: 'Cannot enrol right now',
      detail: 'No connection to the loyalty system. Continue the sale and enrol afterwards.',
    };
  }

  return {
    kind: 'unknown',
    title: 'Could not enrol',
    detail: error?.message ?? 'The till could not complete the enrolment.',
  };
}
