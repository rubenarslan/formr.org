<?php

use Atrox\Haikunator;

/**
 * get random animal name
 */
Haikunator::$ADJECTIVES = array(
    "adorable",
    "awkward",
    "adventurous",
    "aggressive",
    "alert",
    "attractive",
    "average",
    "beautiful",
    "blue-eyed ",
    "bored",
    "bloody",
    "blushing",
    "bright",
    "clean",
    "clear",
    "cloudy",
    "colorful",
    "crowded",
    "cute",
    "dark",
    "drab",
    "distinct",
    "dull",
    "elegant",
    "excited",
    "fancy",
    "filthy",
    "glamorous",
    "gleaming",
    "gorgeous",
    "graceful",
    "grotesque",
    "handsome",
    "homely",
    "light",
    "long",
    "magnificent",
    "misty",
    "motionless",
    "muddy",
    "old-fashioned",
    "plain",
    "poised",
    "precious",
    "quaint",
    "quirky",
    "shiny",
    "smoggy",
    "sparkling",
    "spotless",
    "stormy",
    "strange",
    "unsightly",
    "unusual",
    "wide-eyed ",
    "alive",
    "annoying",
    "bad",
    "better",
    "beautiful",
    "brainy",
    "breakable",
    "busy",
    "careful",
    "cautious",
    "clever",
    "clumsy",
    "concerned",
    "cool",
    "crazy",
    "curious",
    "dead",
    "different",
    "difficult",
    "doubtful",
    "easy",
    "expensive",
    "famous",
    "fragile",
    "frail",
    "gifted",
    "helpful",
    "helpless",
    "horrible",
    "important",
    "impossible",
    "innocent",
    "inquisitive",
    "modern",
    "odd",
    "open",
    "outstanding",
    "poor",
    "powerful",
    "prickly",
    "puzzled",
    "real",
    "rich",
    "shy",
    "sleepy",
    "stupid",
    "super",
    "talented",
    "tame",
    "tender",
    "tough",
    "vast",
    "wandering",
    "wild",
    "wasted",
    "agreeable",
    "amused",
    "brave",
    "calm",
    "charming",
    "cheerful",
    "comfortable",
    "cooperative",
    "courageous",
    "delightful",
    "determined",
    "eager",
    "elated",
    "enchanting",
    "encouraging",
    "energetic",
    "enthusiastic",
    "excited",
    "exuberant",
    "fair",
    "faithful",
    "fantastic",
    "fine",
    "friendly",
    "funny",
    "gentle",
    "glorious",
    "good",
    "happy",
    "healthy",
    "helpful",
    "hilarious",
    "hungover",
    "jolly",
    "joyous",
    "kind",
    "lame",
    "lively",
    "lovely",
    "lucky",
    "nice",
    "obedient",
    "perfect",
    "pleasant",
    "proud",
    "relieved",
    "silly",
    "smiling",
    "splendid",
    "successful",
    "thankful",
    "thoughtful",
    "victorious",
    "vivacious",
    "witty",
    "wonderful",
    "zealous",
    "zany"
);
Haikunator::$NOUNS = array("Aardvark",
    "Adelie"
    , "Hound"
    , "Bush Elephant"
    , "Forest Elephant"
    , "Civet"
    , "Elephant"
    , "Penguin"
    , "Tree Toad"
    , "Akbash"
    , "Akita"
    , "Albatross"
    , "Alligator"
    , "Bulldog"
    , "Angelfish"
    , "Ant"
    , "Anteater"
    , "Aardvark"
    , "Antelope"
    , "Fox"
    , "Hare"
    , "Wolf"
    , "Armadillo"
    , "Asian Elephant"
    , "Mist"
    , "Shepherd"
    , "Avocet"
    , "Axolotl"
    , "Baboon"
    , "Camel"
    , "Badger"
    , "Bandicoot"
    , "Barb"
    , "Barn Owl"
    , "Barnacle"
    , "Barracuda"
    , "Basking"
    , "Bat"
    , "Beagle"
    , "Bear"
    , "Bearded Dragon"
    , "Beaver"
    , "Beetle"
    , "Bengal Tiger"
    , "Bichon"
    , "Binturong"
    , "Bird"
    , "Bison"
    , "Black Bear"
    , "Rhinoceros"
    , "Blue Whale"
    , "Bluetick"
    , "Bobcat"
    , "Bombay"
    , "Bonobo"
    , "Booby"
    , "Orangutan"
    , "Borneo Elephant"
    , "Boykin"
    , "Brown Bear"
    , "Budgerigar"
    , "Buffalo"
    , "Bull Shark"
    , "Bullfrog"
    , "Bumblebee"
    , "Butterfly"
    , "Fish"
    , "Caiman"
    , "Lizard"
    , "Canaan"
    , "Capybara"
    , "Caracal"
    , "Carolina"
    , "Cassowary"
    , "Cat"
    , "Caterpillar"
    , "Catfish"
    , "Centipede"
    , "Cesky"
    , "Fousek"
    , "Chameleon"
    , "Chamois"
    , "Cheetah"
    , "Chicken"
    , "Chihuahua"
    , "Chimpanzee"
    , "Chinchilla"
    , "Chinook"
    , "Chinstrap"
    , "Chipmunk"
    , "Cichlid"
    , "Leopard"
    , "Clown Fish"
    , "Clumber"
    , "Coati"
    , "Cockroach"
    , "Collared Peccary"
    , "Common Buzzard"
    , "Loon"
    , "Toad"
    , "Coral"
    , "Cottontop"
    , "Tamarin"
    , "Cougar"
    , "Cow"
    , "Coyote"
    , "Crab"
    , "Macaque"
    , "Crane"
    , "Crested Penguin"
    , "Crocodile"
    , "Cuscus"
    , "Cuttlefish"
    , "Dachshund"
    , "Dalmatian"
    , "Frog"
    , "Deer"
    , "Desert Tortoise"
    , "Bracke"
    , "Dhole"
    , "Dingo"
    , "Discus"
    , "Dodo"
    , "Dog"
    , "Dogo"
    , "Argentino"
    , "Dolphin"
    , "Donkey"
    , "Dormouse"
    , "Dragonfly"
    , "Drever"
    , "Duck"
    , "Dugong"
    , "Dunker"
    , "Dusky"
    , "Eagle"
    , "Earwig "
    , "Gorilla"
    , "Echidna"
    , "Mau"
    , "Electric Eel"
    , "Elephant Seal"
    , "Elephant Shrew"
    , "Emu"
    , "Falcon"
    , "Fennec"
    , "Ferret"
    , "Fin Whale"
    , "Fire-Bellied Toad"
    , "Fishing Cat"
    , "Flamingo"
    , "Flounder"
    , "Fly"
    , "Flying Squirrel"
    , "Fossa"
    , "Frigatebird"
    , "Frilled Lizard"
    , "Fur Seal"
    , "Gar"
    , "Gecko"
    , "Gentoo Penguin"
    , "Gerbil"
    , "Gharial"
    , "Giant Clam"
    , "Gibbon"
    , "Gila Monster"
    , "Giraffe"
    , "Glass Lizard"
    , "Glow Worm"
    , "Goat"
    , "Oriole"
    , "Goose"
    , "Gopher"
    , "Grasshopper"
    , "Great Dane"
    , "Grey Seal"
    , "Greyhound"
    , "Grizzly Bear"
    , "Grouse"
    , "Guinea Fowl"
    , "Guinea Pig"
    , "Guppy"
    , "Hammerhead"
    , "Shark"
    , "Hamster"
    , "Harrier"
    , "Hedgehog"
    , "Hercules Beetle"
    , "Hermit Crab"
    , "Heron"
    , "Himalayan"
    , "Hippopotamus"
    , "Honey Bee"
    , "Horn Shark"
    , "Horned Frog"
    , "Horse"
    , "Horseshoe Crab"
    , "Howler Monkey"
    , "Human"
    , "Humboldt"
    , "Hummingbird"
    , "Humpback Whale"
    , "Hyena"
    , "Ibis"
    , "Iguana"
    , "Impala"
    , "Indian Elephant"
    , "Indian Rhinoceros"
    , "Indochinese Tiger"
    , "Indri"
    , "Insect"
    , "Jackal"
    , "Jaguar"
    , "Chin"
    , "Jellyfish"
    , "Kakapo"
    , "Kangaroo"
    , "Killer Whale"
    , "King Crab"
    , "King Penguin"
    , "Kingfisher"
    , "Kiwi"
    , "Koala"
    , "Komodo Dragon"
    , "Kudu"
    , "Ladybird"
    , "Leaf-Tailed Gecko"
    , "Lemming"
    , "Lemur"
    , "Leopard Cat"
    , "Leopard Seal"
    , "Leopard Tortoise"
    , "Liger"
    , "Lion"
    , "Lionfish"
    , "Little Penguin"
    , "Llama"
    , "Lobster"
    , "Owl"
    , "Lynx"
    , "Macaroni Penguin"
    , "Macaw"
    , "Magpie"
    , "Malayan Civet"
    , "Malayan Tiger"
    , "Manatee"
    , "Mandrill"
    , "Manta Ray"
    , "Marine Toad"
    , "Markhor"
    , "Marsh Frog"
    , "Mastiff"
    , "Mayfly"
    , "Meerkat"
    , "Millipede"
    , "Minke Whale"
    , "Mole"
    , "Molly"
    , "Mongoose"
    , "Monitor"
    , "Monkey"
    , "Moorhen"
    , "Moose"
    , "Eel"
    , "Moray"
    , "Moth"
    , "Mountain Lion"
    , "Mouse"
    , "Mule"
    , "Newt"
    , "Nightingale"
    , "Numbat"
    , "Nurse Shark"
    , "Ocelot"
    , "Octopus"
    , "Okapi"
    , "Olm"
    , "Opossum"
    , "Ostrich"
    , "Otter"
    , "Oyster"
    , "Pademelon"
    , "Panther"
    , "Parrot"
    , "Patas Monkey"
    , "Peacock"
    , "Pelican"
    , "Persian"
    , "Pheasant"
    , "Pied Tamarin"
    , "Pig"
    , "Pika"
    , "Pike"
    , "Piranha"
    , "Platypus"
    , "Pointer"
    , "Polar Bear"
    , "Pond Skater"
    , "Poodle"
    , "Pool Frog"
    , "Porcupine"
    , "Possum"
    , "Prawn"
    , "Proboscis Monkey"
    , "Puffer Fish"
    , "Puffin"
    , "Pug"
    , "Puma"
    , "Marmoset"
    , "Pygmy"
    , "Quail"
    , "Quetzal"
    , "Quokka"
    , "Quoll"
    , "Rabbit"
    , "Raccoon"
    , "Rat"
    , "Rattlesnake"
    , "Red Panda"
    , "Red Wolf"
    , "Reindeer"
    , "River Dolphin"
    , "River Turtle"
    , "Robin"
    , "Rock Hyrax"
    , "Rockhopper"
    , "Roseate Spoonbill"
    , "Royal Penguin"
    , "Russian Blue"
    , "Salamander"
    , "Sand Lizard"
    , "Saola"
    , "Scorpion"
    , "Scorpion Fish"
    , "Sea Dragon"
    , "Sea Lion"
    , "Sea Otter"
    , "Sea Slug"
    , "Sea Squirt"
    , "Sea Turtle"
    , "Sea Urchin"
    , "Seahorse"
    , "Seal"
    , "Serval"
    , "Sheep"
    , "Shrimp"
    , "Siberian Tiger"
    , "Silver Dollar"
    , "Skunk"
    , "Sloth"
    , "Slow Worm"
    , "Snail"
    , "Snake"
    , "Snapping Turtle"
    , "Snowshoe"
    , "Snowy Owl"
    , "Spadefoot Toad"
    , "Sparrow"
    , "Spectacled Bear"
    , "Sperm Whale"
    , "Spider Monkey"
    , "Dogfish"
    , "Sponge"
    , "Squid"
    , "Squirrel"
    , "Squirrel Monkey"
    , "Stag Beetle"
    , "Starfish"
    , "Stickbug"
    , "Stingray"
    , "Stoat"
    , "Sun Bear"
    , "Swan"
    , "Tang"
    , "Tapir"
    , "Tarsier"
    , "Tasmanian Devil"
    , "Tawny Owl"
    , "Termite"
    , "Tetra"
    , "Thorny Devil"
    , "Tiffany"
    , "Tiger"
    , "Tiger Salamander"
    , "Tiger Shark"
    , "Tortoise"
    , "Toucan"
    , "Tree Frog"
    , "Tropicbird"
    , "Tuatara"
    , "Turkey"
    , "Turtle"
    , "Uakari"
    , "Uguisu"
    , "Umbrellabird"
    , "Vampire Bat"
    , "Vervet Monkey"
    , "Vulture"
    , "Wallaby"
    , "Walrus"
    , "Warthog"
    , "Wasp"
    , "Water Buffalo"
    , "Water Dragon"
    , "Water Vole"
    , "Weasel"
    , "Whale Shark"
    , "White Tiger"
    , "Wild Boar"
    , "Wildebeest"
    , "Wolverine"
    , "Wombat"
    , "Woodlouse"
    , "Woodpecker"
    , "Woolly Mammoth"
    , "Woolly Monkey"
    , "Wrasse"
    , "Yak"
    , "Yellow-Eyed Penguin"
    , "Zebra"
    , "Zebra Shark"
    , "Zebu");

class AnimalName extends Atrox\Haikunator {
    
}
