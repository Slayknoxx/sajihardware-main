(function () {
  const formCard = document.getElementById('formCard');
  const pendingCard = document.getElementById('pendingCard');
  const successCard = document.getElementById('successCard');
  const failedCard = document.getElementById('failedCard');

  const payForm = document.getElementById('payForm');
  const submitBtn = document.getElementById('submitBtn');
  const errorMsg = document.getElementById('errorMsg');

  let pollTimer = null;
  let pollAttempts = 0;
  const MAX_POLL_ATTEMPTS = 40; // ~2 minutes at 3s interval

  function showCard(card) {
    [formCard, pendingCard, successCard, failedCard].forEach(c => c.classList.add('hidden'));
    card.classList.remove('hidden');
  }

  function resetToForm() {
    stopPolling();
    payForm.reset();
    errorMsg.textContent = '';
    showCard(formCard);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    pollAttempts = 0;
  }

  payForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    errorMsg.textContent = '';

    const phone = document.getElementById('phone').value.trim();
    const amount = document.getElementById('amount').value.trim();

    if (!phone || !amount) {
      errorMsg.textContent = 'Please fill in both fields.';
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending…';

    try {
      const res = await fetch('api/stk_push.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone, amount })
      });

      const data = await res.json();

      if (!res.ok || !data.success) {
        errorMsg.textContent = data.message || 'Something went wrong. Please try again.';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send STK Push';
        return;
      }

      document.getElementById('pendingPhone').textContent = phone;
      document.getElementById('pendingAmount').textContent = amount;
      showCard(pendingCard);

      startPolling(data.checkoutRequestId, phone, amount);
    } catch (err) {
      errorMsg.textContent = 'Could not reach the server. Check your connection and try again.';
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Send STK Push';
    }
  });

  function startPolling(checkoutRequestId, phone, amount) {
    stopPolling();
    pollTimer = setInterval(async () => {
      pollAttempts++;

      if (pollAttempts > MAX_POLL_ATTEMPTS) {
        stopPolling();
        document.getElementById('failedReason').textContent =
          'We did not get a confirmation in time. If you completed the prompt, please check your statement before retrying.';
        showCard(failedCard);
        return;
      }

      try {
        const res = await fetch('api/status.php?checkoutRequestId=' + encodeURIComponent(checkoutRequestId));
        const data = await res.json();

        if (!data.success) return; // keep polling

        if (data.status === 'SUCCESS') {
          stopPolling();
          document.getElementById('successAmount').textContent = data.amount;
          document.getElementById('successReceipt').textContent = data.mpesaReceipt || 'N/A';
          showCard(successCard);
        } else if (data.status === 'FAILED') {
          stopPolling();
          document.getElementById('failedReason').textContent = data.resultDesc || 'The payment was not completed.';
          showCard(failedCard);
        }
        // if PENDING, keep polling
      } catch (err) {
        // network hiccup, just keep trying until MAX_POLL_ATTEMPTS
      }
    }, 3000);
  }

  document.getElementById('cancelWait').addEventListener('click', resetToForm);
  document.getElementById('successRestart').addEventListener('click', resetToForm);
  document.getElementById('failedRestart').addEventListener('click', resetToForm);
})();
