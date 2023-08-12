# HYGMap

HYGMap is a web app that generates a star map of a selected area of space using the [HYG Database](https://github.com/astronexus/HYG-Database) compiled by David Nash. The app converts distances into light years and adds galactic coordinates to the database. It also optionally displays the names of stars from a selected science fiction universe. Currently, nearly 200 systems from Star Trek have been identified.

## How it works

The HYG Database is a subset of the data in three major catalogs: the Hipparcos Catalog, the Yale Bright Star Catalog (5th Edition), and the Gliese Catalog of Nearby Stars (3rd Edition). You can import my database using the hygmap.sql dump, or build it the way I did from the source HYG database. To import the complete database in one step, just create the database and then import the tables and data:

`mysql -u username -p database_name < hygmap.sql`

Any time the database is updated the dump will be updated in git, you can repeat the above command to update your own copy of the database.

To build the database yourself:

First create the database and then run the create_tables.sql script:

`mysql -u username -p database_name < hygmap.sql`

Then download the HYG comma-separated database from https://github.com/astronexus/HYG-Database and import it into a MySQL table using the included import_hyg.sql script. This script also converts the equatorial coordinates and distance data into Galactic coordinates.

Then run the propernames.sql script to set the official IAU names for each star that has one.

Finally, run populate_trek.sql to populate the fictional names table.

## Coordinate system

Two sets of coordinates are stored for each star in the database. The equatorial (2000.0) RA and declination coordinates, which you can view by selecting a star, and the Galactic Cartesian coordinates. The equatorial coordinates determine the two-dimensional location of a star in the sky and will allow you to view the stars you see on these maps.

The Galactic coordinates place the star in three-dimensional space. (0,0,0) is our Sun's coordinates. The positive X axis points toward the Galactic center, about 26,700 light years away or (26700,0,0). The positive Y axis points toward 90 degrees Galactic longitude, in the direction of the constellation Cygnus. And the Z axis points "up" out of the Galactic plane, toward Coma Berenices.

On the screen, the positive X axis is up toward the top of the screen, Y to the left, and Z out of the screen toward the viewer. You're looking down on the galactic plane from above it.

## Selecting stars

You can choose a specific star from the table at the bottom of the screen. It will list only the stars visible on the current map. After selecting a star, it will have a blue box around it on the map, and details will be displayed to the left of the map. If you check the "center on selection" box, the map will also be moved so that the selected star is in the middle. Selecting a fictional star name from the dropdown at the bottom will automatically center and select that star on the map.
