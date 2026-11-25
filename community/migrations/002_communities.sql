-- Плагин «Сообщество», версия 2.0: сообщества.
--
-- channel.join_policy — как вступают:
--   open     — сразу, кнопкой «Вступить»;
--   approval — заявка, её одобряет модератор канала;
--   closed   — вступить нельзя, добавляет только модератор.
--
-- count_members — денормализация для списка сообществ: считать участников
-- подзапросом на каждый канал дорого, а цифра нужна в списке.

ALTER TABLE plugin_community_channels ADD COLUMN join_policy TEXT NOT NULL DEFAULT 'open';
ALTER TABLE plugin_community_channels ADD COLUMN count_members INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_community_members_user
    ON plugin_community_members(user_id, status);
CREATE INDEX IF NOT EXISTS idx_community_members_channel
    ON plugin_community_members(channel_id, status);
