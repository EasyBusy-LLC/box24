export function createWidgetStateController({ stateNode, stateText, retryButton, chatContainer }) {
  function setState(message, kind = 'loading') {
    stateText.textContent = String(message || '');
    stateNode.dataset.kind = kind;
    stateNode.hidden = false;
    retryButton.hidden = kind !== 'error';
  }

  function showChats() {
    stateNode.hidden = true;
    chatContainer.hidden = false;
  }

  return { setState, showChats };
}
