"""Test-only loopback PostgreSQL relay: discard the acknowledgement of a real COMMIT.

No SQL, credentials or wire payloads are logged. The target is the already guarded
disposable cluster. A new process is used for each fault experiment.
"""
import select
import socket
import threading


def relay(client):
    backend = socket.create_connection(("127.0.0.1", 5433), timeout=5)
    front_tail = b""
    pending = b""
    committing = False
    try:
        while True:
            ready, _, _ = select.select([client, backend], [], [], 20)
            if not ready:
                return
            for source in ready:
                data = source.recv(65536)
                if not data:
                    return
                if source is client:
                    front_tail = (front_tail + data)[-128:]
                    if b"COMMIT\x00" in front_tail:
                        committing = True
                    backend.sendall(data)
                elif not committing:
                    client.sendall(data)
                else:
                    pending += data
                    while len(pending) >= 5:
                        length = int.from_bytes(pending[1:5], "big")
                        if len(pending) < length + 1:
                            break
                        frame, pending = pending[:length + 1], pending[length + 1:]
                        if frame[:1] == b"C" and frame[5:] == b"COMMIT\x00":
                            print("COMMIT_ACK_DROPPED", flush=True)
                            return  # PostgreSQL committed; PDO cannot receive that outcome.
                        client.sendall(frame)
    finally:
        for connection in (client, backend):
            try:
                connection.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            connection.close()


server = socket.socket()
server.bind(("127.0.0.1", 0))
server.listen(4)
print("BOOTED " + str(server.getsockname()[1]), flush=True)
while True:
    client, _ = server.accept()
    threading.Thread(target=relay, args=(client,), daemon=True).start()
