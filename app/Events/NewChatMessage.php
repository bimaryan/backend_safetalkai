<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// PENTING: Gunakan ShouldBroadcastNow agar pesan langsung nembak seketika
class NewChatMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(ChatMessage $message)
    {
        $this->message = $message;
    }

    // Tentukan ke channel mana pesan ini disebarkan
    public function broadcastOn(): array
    {
        // Channel ini dinamis sesuai ID Room-nya, misal: 'chat.room.1'
        // Nanti di React lu tinggal subscribe ke channel ini
        return [
            new PrivateChannel('chat.room.' . $this->message->chat_room_id),
        ];
    }

    // Nama event yang akan ditangkap oleh frontend
    public function broadcastAs(): string
    {
        return 'message.new';
    }

    // Data payload yang dikirim ke frontend
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'role' => $this->message->sender_type,
            'text' => $this->message->message,
            'time' => $this->message->created_at->format('H:i'),
            'instruction' => $this->message->instruction,
            // Format disamakan persis dengan format di method index()
        ];
    }
}