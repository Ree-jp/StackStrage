<?php

namespace ree_jp\StackStorage;

use Closure;
use Exception;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\block\BlockLegacyIds;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use ree_jp\stackStorage\api\StackStorageAPI;

class StackStorageService
{
    const BACK = 45;
    const NEXT = 53;
    const CLOSE = 49;
    const SYSTEM_ITEM = 1;

    private int $page = 0;

    /**
     * @param StackStorageAPI $api
     * @param InvMenu $gui
     * @param Player $p
     * @param string $xuid
     * @param Item[] $items
     * @noinspection PhpPropertyOnlyWrittenInspection
     */
    public function __construct(private StackStorageAPI $api, private InvMenu $gui, private Player $p, private string $xuid, public array $items)
    {
        $gui->setName("StackStorage" . StackStoragePlugin::getVersion());
        $gui->setInventoryCloseListener(function (Player $p) use ($api): void {
            $api->closeCache($p->getXuid());
        });
        $gui->setListener(Closure::fromCallable([$this, "onTransaction"]));
        $gui->send($p);
    }

    public function backPage()
    {
        $this->page -= 1;
        $this->refresh();
    }

    public function refresh(): void
    {
        $inv = $this->gui->getInventory();
        $inv->clearAll();

        $chunk = array_chunk($this->items, 45);
        $count = 0;

        if (!isset($chunk[$this->page - 1])) return;

        /** @var Item $item */
        foreach ($chunk[$this->page - 1] as $item) {
            $item = clone $item;

            if ($item->getMaxStackSize() < $item->getCount()) {
                $storeCount = $item->getCount();
                $item->setCount($item->getMaxStackSize());
                $tag = $item->getNamedTag();
                $tag->setString("stackstorage_store_nbt", base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($item->getNamedTag()))));
                $item->setNamedTag($tag);
                $item->setLore(['Count', $storeCount]);
            }
            $inv->setItem($count, $item);
            $count++;
        }

        if (isset($chunk[$this->page])) {
            $item = ItemFactory::getInstance()->getItem(ItemIds::ARROW)->setCustomName('NextPage');
            $inv->setItem(self::NEXT, $this->setSystemItem($item));
        }
        if (isset($chunk[$this->page - 2])) {
            $item = ItemFactory::getInstance()->getItem(ItemIds::ARROW)->setCustomName('BackPage');
            $inv->setItem(self::BACK, $this->setSystemItem($item));
        }
    }

    private function setSystemItem(Item $item): Item
    {
        $tag = $item->getNamedTag();
        $tag->setInt("stackstorage_item_value", self::SYSTEM_ITEM);
        $item->setNamedTag($tag);
        return $item;
    }

    public function nextPage()
    {
        $this->page += 1;
        $this->refresh();
    }

    private function onTransaction(InvMenuTransaction $tran): InvMenuTransactionResult
    {
        if ($tran->getOut()->getId() !== BlockLegacyIds::AIR) {
            switch ($tran->getAction()->getSlot()) {
                case self::BACK:
                    $this->api->backPage($this->xuid);
                    return $tran->discard();

                case self::NEXT:
                    $this->api->nextPage($this->xuid);
                    return $tran->discard();
            }
        }
        if ($tran->getIn()->getId() !== BlockLegacyIds::AIR) {
            $this->api->add($this->xuid, $tran->getIn());
        }
        if ($tran->getOut()->getId() !== BlockLegacyIds::AIR) {
            try {
                $item = $tran->getOut();
                if (!StackStorageAPI::$instance->hasCountFromCache($this->xuid, $item)) throw new Exception('could not reduce items');
                StackStorageAPI::$instance->remove($this->xuid, $item);
            } catch (Exception $e) {
                return $tran->discard();
            }
        }
        return $tran->continue();
    }
}
