import React, { useEffect } from 'react';
import { MapContainer, TileLayer, useMap, Marker, Popup } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { GeoSearchControl, OpenStreetMapProvider } from "leaflet-geosearch";
import "leaflet-geosearch/dist/geosearch.css";

function LeafletgeoSearch() {
    const map = useMap();

    const provider = new OpenStreetMapProvider();

    useEffect(() => {
        map.on('geosearch/showlocation', function (e) {
            e.marker.on('dragend', (e) => console.log(e))
        });

        const searchControl = new GeoSearchControl({
            provider,
            style: "bar",
            // notFoundMessage: 'Sorry, that address could not be found.',
            marker: {
                draggable: true
            },
            // popupFormat: ({ query, result }) => console.log(query),
            resultFormat: (e) => e.result.label,
            maxSuggestions: 0,
            showMarker: true,
            // autoClose: true,
            // clearSearchLabel: true,
        });


        map.addControl(searchControl);

        return () => map.removeControl(searchControl);
    }, []);

    return null;
}

const MapView = () => {
    const position = [51.505, -0.09]
    // const map = useMap();


    // map.on('geosearch/showlocation', function (e) {
    //     alert('salam')
    //     console.log(e);
    // });

    const form = document.querySelector('.leaflet-control-geosearch > form');
    const input = document.querySelector('input[type="text"].glass');

    const onInputChange = (e) => {
        input.dispatchEvent(new Event('keyup'));
        form.dispatchEvent(new Event('submit'));
        input.value = e.target.value
    }


    return (
        <>
            strret:<input className='rbt-input-main form-control rbt-input' type="text" onChange={onInputChange} />
            <br />
            <MapContainer center={position} zoom={13} scrollWheelZoom={true}>
                <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                />
                <LeafletgeoSearch />
                {/* <Marker position={[51.505, -0.09]}>
                    <Popup>
                        A pretty CSS3 popup. <br /> Easily customizable.
                    </Popup>
                </Marker> */}
            </MapContainer>
        </>
    )
}

export default MapView