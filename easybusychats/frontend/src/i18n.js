const messages = {
  'ru-RU': {
    loadingCompany: 'Получение данных компании…', loadingChats: 'Загрузка EasyBusy Chats…', retry: 'Повторить',
    createContact: 'Создать контакт', linkContact: 'Связать с контактом', viewContact: 'Открыть контакт', unlinkContact: 'Отвязать контакт',
    deals: 'Сделки', createDeal: 'Создать сделку', openDeal: 'Открыть', linkDeal: 'Связать', unlinkDeal: 'Отвязать',
    searchContacts: 'Поиск контакта', searchDeals: 'Поиск сделки', search: 'Поиск', cancel: 'Отмена', save: 'Сохранить',
    name: 'Имя', phone: 'Телефон', email: 'Email', dealTitle: 'Название сделки', noResults: 'Ничего не найдено.',
    confirmUnlinkContact: 'Отвязать чат от контакта и связанных сделок?', confirmUnlinkDeal: 'Отвязать эту сделку от чата?', operationFailed: 'Операция не выполнена.',
    chatNotFound: 'Чат не найден в EasyBusy. Проверьте связь и повторите открытие.', chatIdentityMissing: 'В CRM-связи отсутствует идентификатор EasyBusy-чата.',
    findByPhone: 'Найти по телефону', phoneMatches: 'Найденные контакты', phoneContactLinked: 'Контакт найден и привязан.',
    phoneMultipleFound: 'Найдено несколько контактов', phoneContactNotFound: 'Контакт с таким телефоном не найден.',
    phoneMissing: 'У клиента не указан телефон.', phoneInvalid: 'Телефон некорректен или слишком короткий.',
    phonePermissionDenied: 'Недостаточно прав для поиска CRM-контактов.', phoneSearchInProgress: 'Поиск по телефону уже выполняется.',
    phoneSearchFailed: 'Не удалось выполнить поиск по телефону.',
  },
  'en-US': {
    loadingCompany: 'Loading company…', loadingChats: 'Loading EasyBusy Chats…', retry: 'Retry',
    createContact: 'Create contact', linkContact: 'Link contact', viewContact: 'Open contact', unlinkContact: 'Unlink contact',
    deals: 'Deals', createDeal: 'Create deal', openDeal: 'Open', linkDeal: 'Link', unlinkDeal: 'Unlink',
    searchContacts: 'Search contacts', searchDeals: 'Search deals', search: 'Search', cancel: 'Cancel', save: 'Save',
    name: 'Name', phone: 'Phone', email: 'Email', dealTitle: 'Deal title', noResults: 'Nothing found.',
    confirmUnlinkContact: 'Unlink this chat from the contact and linked deals?', confirmUnlinkDeal: 'Unlink this deal from the chat?', operationFailed: 'The operation failed.',
    chatNotFound: 'The chat was not found in EasyBusy. Check the link and try again.', chatIdentityMissing: 'The CRM mapping does not contain an EasyBusy chat ID.',
    findByPhone: 'Find by phone', phoneMatches: 'Matching contacts', phoneContactLinked: 'The contact was found and linked.',
    phoneMultipleFound: 'Multiple contacts found', phoneContactNotFound: 'No contact has this phone number.',
    phoneMissing: 'The customer has no phone number.', phoneInvalid: 'The phone number is invalid or too short.',
    phonePermissionDenied: 'You do not have permission to search CRM contacts.', phoneSearchInProgress: 'A phone search is already running.',
    phoneSearchFailed: 'The phone search failed.',
  },
  'uk-UA': {
    loadingCompany: 'Отримання даних компанії…', loadingChats: 'Завантаження EasyBusy Chats…', retry: 'Повторити',
    createContact: 'Створити контакт', linkContact: 'Пов’язати з контактом', viewContact: 'Відкрити контакт', unlinkContact: 'Відв’язати контакт',
    deals: 'Угоди', createDeal: 'Створити угоду', openDeal: 'Відкрити', linkDeal: 'Пов’язати', unlinkDeal: 'Відв’язати',
    searchContacts: 'Пошук контакту', searchDeals: 'Пошук угоди', search: 'Пошук', cancel: 'Скасувати', save: 'Зберегти',
    name: 'Ім’я', phone: 'Телефон', email: 'Email', dealTitle: 'Назва угоди', noResults: 'Нічого не знайдено.',
    confirmUnlinkContact: 'Відв’язати чат від контакту та пов’язаних угод?', confirmUnlinkDeal: 'Відв’язати цю угоду від чату?', operationFailed: 'Операцію не виконано.',
    chatNotFound: 'Чат не знайдено в EasyBusy. Перевірте зв’язок і повторіть відкриття.', chatIdentityMissing: 'У CRM-зв’язку відсутній ідентифікатор EasyBusy-чату.',
    findByPhone: 'Знайти за телефоном', phoneMatches: 'Знайдені контакти', phoneContactLinked: 'Контакт знайдено та пов’язано.',
    phoneMultipleFound: 'Знайдено кілька контактів', phoneContactNotFound: 'Контакт із таким телефоном не знайдено.',
    phoneMissing: 'У клієнта немає телефону.', phoneInvalid: 'Телефон некоректний або занадто короткий.',
    phonePermissionDenied: 'Недостатньо прав для пошуку CRM-контактів.', phoneSearchInProgress: 'Пошук за телефоном уже виконується.',
    phoneSearchFailed: 'Не вдалося виконати пошук за телефоном.',
  },
};

export function createI18n(language) {
  const dictionary = messages[language] || messages['ru-RU'];
  return (key) => dictionary[key] || messages['en-US'][key] || key;
}
