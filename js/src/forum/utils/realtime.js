import app from 'flarum/forum/app';

/**
 * Listens on a group's private channel when flarum/realtime is installed,
 * and does nothing otherwise. The server authorises the subscription, so
 * non-members never receive it. Returns the function that stops listening.
 */
export function listenToGroup(groupId, handlers) {
  const name = `private-sg-group.${groupId}`;
  const pusher = app.realtime?.pusher || app.pusher || null;
  let channel = null;

  try {
    channel = pusher?.subscribe ? pusher.subscribe(name) : app.realtime?.subscribePrivate?.(name);
    Object.entries(handlers).forEach(([event, handler]) => channel?.bind?.(event, handler));
  } catch (e) {
    channel = null;
  }

  return () => {
    if (!channel) return;
    try {
      Object.keys(handlers).forEach((event) => channel.unbind?.(event));
      pusher?.unsubscribe ? pusher.unsubscribe(name) : app.realtime?.unsubscribe?.(name);
    } catch (e) {}
  };
}
