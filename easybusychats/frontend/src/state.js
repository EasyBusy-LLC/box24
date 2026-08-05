export const initialState = Object.freeze({
  chat: null,
  context: null,
  status: 'idle',
  error: null,
  revision: 0,
});

export function reducer(state, action) {
  switch (action?.type) {
    case 'chat/loading':
      return { ...state, chat: action.chat, status: 'loading', error: null, revision: state.revision + 1 };
    case 'chat/loaded':
      return { ...state, chat: action.chat, context: action.context, status: 'ready', error: null };
    case 'chat/mutating':
      return { ...state, status: 'mutating', error: null };
    case 'chat/error':
      return { ...state, status: 'error', error: action.error || null };
    case 'chat/reset':
      return { ...initialState, revision: state.revision + 1 };
    default:
      return state;
  }
}

export function createStore() {
  let state = { ...initialState };
  const listeners = new Set();
  return {
    getState: () => state,
    dispatch(action) {
      state = reducer(state, action);
      listeners.forEach((listener) => listener(state));
      return state;
    },
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
}
